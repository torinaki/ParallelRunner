<?php

namespace shvetsgroup\ParallelRunner\Console\Command;

use Behat\Behat\Console\Command\BehatCommand,
  Behat\Behat\Event\SuiteEvent;

use Behat\Behat\DataCollector\LoggerDataCollector;
use Behat\Behat\Event\FeatureEvent;
use Behat\Behat\Formatter\FormatterManager;
use Behat\Behat\Tester\FeatureTester;
use Behat\Behat\Tester\ScenarioTester;
use Behat\Gherkin\Gherkin;
use Behat\Gherkin\Node\FeatureNode;
use Behat\Gherkin\Node\ScenarioNode;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\ContainerInterface,
  Symfony\Component\Console\Input\InputOption,
  Symfony\Component\Console\Input\InputInterface,
  Symfony\Component\Console\Output\OutputInterface,
  Symfony\Component\Process\Process;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;

class ParallelRunnerCommand extends BehatCommand
{
    /**
     * @var bool Number of parallel processes.
     */
    protected $processCount = 1;

    /**
     * @var Process[]
     */
    protected $processes = array();

    /**
     * @var int Worker ID.
     */
    protected $workerId;

    /**
     * @var string Suite ID.
     */
    protected $suiteID;

    /**
     * @var string Session ID generated and provided by parent process
     */
    protected $sessionId;

    /**
     * @var string Path where exchanged data between parent and child processes will be stored
     */
    protected $outputPath;

    protected $fileOffsets = array();

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (isset($this->workerId)) {
            $this->runWorker();

            return $this->getCliReturnCode();
        } else {
            if ($this->processCount > 1) {
                $this->beforeSuite();
                $this->runParallel($input);
                $this->afterSuite();

                return $this->getCliReturnCode();
            } else {
                return parent::execute($input, $output);
            }
        }
    }

    /**
     * Run features in parallel.
     */
    protected function runParallel(InputInterface $input)
    {

        // We don't need any formatters, but event recorder for worker process.
        $formatterManager = $this->getContainer()->get('behat.formatter.manager');
        $formatterManager->disableFormatters();

        // Prepare parameters string.
        $env = 'export QUERY_STRING="start_debug=1&debug_stop=1&debug_fastfile=1&debug_coverage=1&use_remote=1
    &send_sess_end=1&debug_session_id=2000&debug_start_session=1&debug_port=10137&debug_host=91.221.62.112" && export PHP_IDE_CONFIG="serverName=dmitryb.d.intexsys.lv"';
        $env = 'export QUERY_STRING="start_debug=0"';
        $command_template = array($env . ' && ' . realpath($_SERVER['SCRIPT_FILENAME']));
        foreach ($input->getArguments() as $argument) {
            $command_template[] = $argument;
        }
        foreach ($input->getOptions() as $option => $value) {
            if ($value && $option != 'parallel' && $option != 'profile') {
                $command_template[] = "--$option='$value'";
            }
        }
        if (!$input->getOption('cache')) {
            $command_template[] = "--cache='" . sys_get_temp_dir() . "/behat'";
        }
        $command_template[] = "--out='/dev/null'";

        // Spin new test processes while there are still tasks in queue.
        $this->processes = array();
        $profiles = $this->getContainer()->getParameter('parallel.profiles');
        for ($i = 0; $i < $this->processCount; $i++) {
            $command = $command_template;
            $worker_data = json_encode(
                array(
                    'workerId' => $i,
                    'processCount' => $this->processCount,
                    'sessionId' => $this->getSessionId(),
                )
            );
            $command[] = "--worker='$worker_data'";
            if (isset($profiles[$i])) {
                $command[] = "--profile='{$profiles[$i]}'";
            }
            $final_command = implode(' ', $command);
            $this->processes[$i] = new Process($final_command);
            $this->processes[$i]->start();
        }

        // catch app interruption
        $this->registerParentSignal();

        $time = microtime(true);
        // Wait while all behat processes will done their job and show output
        while ($this->calculateRunningProcessCount() > 0) {
            sleep(1);
            $this->printOutput();
        }
        $time = microtime(true) - $time;

        $this->printSummary($time);

        // TODO: Must XML JUnit files must be merged
    }

    /**
     * Run a single thread of testing. Stockpiles a test result exports into a temp dir.
     */
    protected function runWorker()
    {
        $this->registerWorkerSignal();
        /** @var FormatterManager $formatterManager */
        $formatterManager = $this->getContainer()->get('behat.formatter.manager');
        $formatterManager->setFormattersParameter('output_path', $this->getOutputPath());
        $formatterManager->setFormattersParameter('worker_id', $this->getWorkerId());

        /** @var Gherkin $gherkin */
        $gherkin = $this->getContainer()->get('gherkin');
        /** @var ScenarioTester $tester */
        $tester = $this->getContainer()->get('behat.tester.scenario');
        $tester->setDryRun($this->isDryRun());

        $dispatcher = $this->getContainer()->get('behat.event_dispatcher');
        $contextParameters = $this->getContainer()->get('behat.context.dispatcher')->getContextParameters();

        // TODO: Move all this logic to custom FeatureTester class
        foreach ($this->getFeaturesPaths() as $path) {

            $scenarioCount = 1;
            /** @var FeatureNode[] $features */
            $features = $gherkin->load((string) $path);

            foreach ($features as $feature) {
                $dispatcher->dispatch(
                    'beforeFeature', new FeatureEvent($feature, $contextParameters)
                );
                /** @var ScenarioNode[] $scenarios */
                $scenarios = $feature->getScenarios();
                foreach ($scenarios as $scenario) {
                    if (($scenarioCount % $this->processCount) == $this->workerId) {
                        $scenario->accept($tester);
                    }
                    $scenarioCount++;
                }
                $dispatcher->dispatch(
                    'afterFeature', new FeatureEvent($feature, $contextParameters)
                );
            }
        }
    }

    protected function printSummary($time)
    {
        $minutes    = floor($time / 60);
        $seconds    = round($time - ($minutes * 60), 3);

        echo PHP_EOL . $minutes . 'm' . $seconds . 's' . PHP_EOL;
    }

    protected function calculateRunningProcessCount()
    {
        foreach ($this->processes as $i => $process) {
            if (!$this->processes[$i]->isRunning()) {
                unset($this->processes[$i]);
            }
        }
        return count($this->processes);
    }

    protected function closeDataExchange()
    {
        rmdir($this->outputPath);
    }

    public function getOutputPath()
    {
        if (null === $this->outputPath) {
            $this->outputPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'behat-' . $this->getSessionId();
            if (!is_dir($this->outputPath)) {
                mkdir($this->outputPath);
            }
        }
        return $this->outputPath;
    }

    /**
     * Process events, dumped by children processes (in other words, do the formatting job).
     * @return Event[]
     */
    protected function printOutput()
    {
        $files = glob($this->getOutputPath() . '/TEST-*', GLOB_BRACE);
        foreach ($files as $file) {
            if (!isset($this->fileOffsets[$file])) {
                $this->fileOffsets[$file] = 0;
            }
            $output = file_get_contents($file, false, null, $this->fileOffsets[$file]);
            $this->fileOffsets[$file] += strlen($output);
            echo $output;
        }
    }

    /**
     * Register termination handler, which correctly shuts down child processes on parent process termination.
     */
    protected function registerParentSignal()
    {
        if (!function_exists('pcntl_signal')) {
            trigger_error('PCNTL extension not installed. Child processes can\'t be terminated properly', E_USER_WARNING);
            return;
        }
        $dispatcher = $this->getContainer()->get('behat.event_dispatcher');
        $logger = $this->getContainer()->get('behat.logger');
        $parameters = $this->getContainer()->get('behat.context.dispatcher')->getContextParameters();

        $processes = $this->processes;
        $function = function ($signal) use ($dispatcher, $parameters, $logger, $processes) {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(30);
                }
            }
            $dispatcher->dispatch('afterSuite', new SuiteEvent($logger, $parameters, FALSE));
            throw new \Exception("Received Kill signal $signal");
        };
        pcntl_signal(SIGINT, $function);
        pcntl_signal(SIGTERM, $function);
        pcntl_signal(SIGQUIT, $function);
    }

    /**
     * Mimics default beaht's shutdown handler, but allso works on SIGTERM.
     */
    protected function registerWorkerSignal()
    {
        if (function_exists('pcntl_signal')) {
            /** @var EventDispatcher $logger */
            $dispatcher = $this->getContainer()->get('behat.event_dispatcher');
            /** @var LoggerDataCollector $logger */
            $logger = $this->getContainer()->get('behat.logger');
            $parameters = $this->getContainer()->get('behat.context.dispatcher')->getContextParameters();

            $function = function () use ($dispatcher, $parameters, $logger) {
                $dispatcher->dispatch('afterSuite', new SuiteEvent($logger, $parameters, false));
                exit(1);
            };
            pcntl_signal(SIGINT, $function);
            pcntl_signal(SIGTERM, $function);
            pcntl_signal(SIGQUIT, $function);
        }
    }

    /**
     * @param bool Number of parallel processes.
     * @return ParallelRunnerCommand
     */
    public function setProcessCount($value)
    {
        $this->processCount = $value;
        return $this;
    }

    /**
     * @param bool Whether or not run the test as worker.
     * @return ParallelRunnerCommand
     */
    public function setWorkerId($value)
    {
        $this->workerId = $value;
        return $this;
    }

    public function getWorkerId()
    {
        return $this->workerId;
    }

    /**
     * @param string $sessionID
     * @return ParallelRunnerCommand
     */
    public function setSessionId($sessionID)
    {
        $this->sessionId = $sessionID;
        return $this;
    }

    /**
     * Session ID will be auto generated if not set
     * @throws Exception
     * @return string
     */
    protected function getSessionId()
    {
        if (null === $this->sessionId) {
            if ($this->isWorker()) {
                throw new Exception('Session ID not provided');
            }
            $this->sessionId = time() . '-' . rand(1, PHP_INT_MAX);
        }
        return $this->sessionId;
    }

    protected function isSetSessionId()
    {
        return (bool)$this->sessionId;
    }

    protected function isWorker()
    {
        return (null !== $this->workerId);
    }

    public static function getFileName(FeatureNode $feature)
    {
        return 'TEST-' . basename($feature->getFile(), '.feature') . '.txt';
    }

    private function cleanTemporaryData()
    {
        $path = $this->getOutputPath();
        $files = glob($path . '/*', GLOB_BRACE);
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($path)) {
            rmdir($path);
        }
    }

    public function __destruct()
    {
        if (!$this->isWorker()) {
            $this->cleanTemporaryData();
        }
    }
}
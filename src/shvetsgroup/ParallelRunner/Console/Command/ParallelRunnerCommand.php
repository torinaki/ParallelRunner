<?php
/**
 * @copyright 2013 Alexander Shvets
 * @license MIT
 */

namespace shvetsgroup\ParallelRunner\Console\Command;

use Behat\Behat\Console\Command\BehatCommand,
  Behat\Behat\Event\SuiteEvent;

use Behat\Behat\DataCollector\LoggerDataCollector;
use Behat\Gherkin\Gherkin;
use shvetsgroup\ParallelRunner\Service\EventService;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\ContainerInterface,
  Symfony\Component\Console\Input\InputOption,
  Symfony\Component\Console\Input\InputInterface,
  Symfony\Component\Console\Output\OutputInterface,
  Symfony\Component\Process\Process;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Behat parallel runner client console command
 *
 * @author Alexander Shvets <apang@softwaredevelopment.ca>
 */
class ParallelRunnerCommand extends BehatCommand
{
    /**
     * @var bool Number of parallel processes.
     */
    protected $processCount = 1;

    /**
     * @var array of Process classes.
     */
    protected $processes = array();

    /**
     * @var int Worker ID.
     */
    protected $workerID;

    /**
     * @var string Suite ID.
     */
    protected $suiteID;

    /**
     * @var string Session ID generated and provided by parent process
     */
    protected $sessionID;

    /**
     * @var string Path where exchanged data between parent and child processes will be stored
     */
    protected $resultDir;

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (isset($this->workerID)) {
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
        $this->initDataExchange();

        /** @var EventService $eventService */
        $eventService = $this->getContainer()->get('parallel.service.event');

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
                    'workerID' => $i,
                    'processCount' => $this->processCount,
                    'sessionID' => $this->getSessionID(),
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

        // Print test results while workers do the testing job.

        // FIXES: This is container for all events. Unfortunately if any of event's object will be destructed,
        // than selenium session will be closed(selenium session of child process "magically" unserialized from
        // fetched data). So to prevent such behavior we need keep all objects till
        // all tests will be end. !!! This fix may be a subject of lot memory usage. !!!
        $events = array();
        do {

            sleep(1);

            if ($_events = $this->fetchData()) {
                $events = array_merge($events, $_events);
                $eventService->replay($_events);
            }

        } while ($this->calculateRunningProcessCount() > 0);

        $this->closeDataExchange();
    }

    /**
     * Run a single thread of testing. Stockpiles a test result exports into a temp dir.
     */
    protected function runWorker()
    {
        $this->initDataExchange();

        $this->registerWorkerSignal();

        // We don't need any formatters, but event recorder for worker process.
        $formatterManager = $this->getContainer()->get('behat.formatter.manager');
        $formatterManager->disableFormatters();
        $formatterManager->initFormatter('recorder');

        /** @var Gherkin $gherkin */
        $gherkin = $this->getContainer()->get('gherkin');
        /** @var EventService $eventService */
        $eventService = $this->getContainer()->get('parallel.service.event');

        $feature_count = 1;
        foreach ($this->getFeaturesPaths() as $path) {
            // Run the tests and record the events.
            $eventService->flushEvents();
            $features = $gherkin->load((string) $path);
            foreach ($features as $feature) {
                if (($feature_count % $this->processCount) == $this->workerID) {
                    $tester = $this->getContainer()->get('behat.tester.feature');
                    $tester->setDryRun($this->isDryRun());
                    $feature->accept($tester);

                    $this->storeData($feature, $eventService->getEvents());

                    $eventService->flushEvents();
                }
                $feature_count++;
            }
        }
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

    protected function initDataExchange()
    {
        if (!$this->isSetSessionId()) {
            $sessionId = time() . '-' . rand(1, PHP_INT_MAX);
            $this->setSessionID($sessionId);
        }

        $this->resultDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'behat-' . $this->getSessionID();
        if (!is_dir($this->resultDir)) {
            mkdir($this->resultDir);
        }
    }

    protected function closeDataExchange()
    {
        rmdir($this->resultDir);
    }

    protected function storeData($feature, array $events)
    {
        $file = str_replace('/', '_', $feature->getFile());
        file_put_contents($this->resultDir . DIRECTORY_SEPARATOR . $file, serialize($events));
    }

    /**
     * Process events, dumped by children processes (in other words, do the formatting job).
     * @param integer $processNumber
     * @return Event[]
     */
    protected function fetchData($processNumber = null)
    {
        $events = array();
        $files = glob($this->resultDir . '/*', GLOB_BRACE);
        foreach ($files as $file) {
            $events = array_merge($events, unserialize(file_get_contents($file)));
            unlink($file);
        }
        return $events;
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
    public function setWorkerID($value)
    {
        $this->workerID = $value;
        return $this;
    }

    /**
     * @param string $sessionID
     * @return ParallelRunnerCommand
     */
    public function setSessionID($sessionID)
    {
        $this->sessionID = $sessionID;
        return $this;
    }

    /**
     * Session ID will be auto generated if not set
     * @throws Exception
     * @return string
     */
    protected function getSessionID()
    {
        if (null === $this->sessionID) {
            throw new Exception('Session ID not provided');
        }
        return $this->sessionID;
    }

    protected function isSetSessionId()
    {
        return (bool)$this->sessionID;
    }
}
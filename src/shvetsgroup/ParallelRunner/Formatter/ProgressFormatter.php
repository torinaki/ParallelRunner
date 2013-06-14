<?php

namespace shvetsgroup\ParallelRunner\Formatter;

use Behat\Behat\Event\FeatureEvent;
use Behat\Behat\Formatter\ProgressFormatter as ProgressFormatterBase,
    Behat\Behat\Exception\FormatterException,
    Behat\Behat\Event\ScenarioEvent;
use Behat\Gherkin\Node\ScenarioNode,
    shvetsgroup\ParallelRunner\Console\Command\ParallelRunnerCommand;

class ProgressFormatter extends ProgressFormatterBase
{
    /**
     * Current XML filename.
     *
     * @var string
     */
    protected $filename;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        $events = array('beforeFeature', 'afterSuite', 'afterStep');

        return array_combine($events, $events);
    }

    public function beforeFeature(FeatureEvent $event)
    {
        $feature = $event->getFeature();
        $file = $feature->getFile();
        $workerId = $this->getParameter('worker_id');
        $this->filename = 'TEST-' . basename($file, '.feature') . '-' . $workerId . '.txt';
    }

    /**
     * {@inheritdoc}
     */
    protected function createOutputStream()
    {
        $outputPath = $this->parameters->get('output_path');

        if (null === $outputPath) {
            throw new FormatterException(sprintf(
                'You should specify "output_path" parameter for %s', get_class($this)
            ));
        } elseif (is_file($outputPath)) {
            throw new FormatterException(sprintf(
                'Directory path expected as "output_path" parameter of %s, but got: %s',
                get_class($this),
                $outputPath
            ));
        }

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0777, true);
        }

        return fopen($outputPath . DIRECTORY_SEPARATOR . $this->filename, 'w');
    }

}

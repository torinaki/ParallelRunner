<?php

namespace shvetsgroup\ParallelRunner\Formatter;

use Behat\Behat\Event\FeatureEvent,
    Behat\Behat\Event\ScenarioEvent;

use Behat\Behat\Formatter\JUnitFormatter as JUnitFormatterBase;

class JUnitFormatter extends JUnitFormatterBase
{

    public function beforeFeature(FeatureEvent $event)
    {
        $feature = $event->getFeature();
        $workerId = $this->getParameter('worker_id');
        $this->filename = 'TEST-' . basename($feature->getFile(), '.feature') . '-' . $workerId . '.txt';

        $this->printTestSuiteHeader($feature);

        $this->stepsCount       = 0;
        $this->testcases        = array();
        $this->exceptionsCount  = 0;
        $this->featureStartTime = microtime(true);
    }

}

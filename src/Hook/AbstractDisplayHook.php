<?php
declare(strict_types=1);

namespace Piano\LinkedProduct\Hook;

use Context;
use Module;

abstract class AbstractDisplayHook
{
    protected Module $module;
    protected Context $context;

    public function __construct(Module $module, Context $context)
    {
        $this->module = $module;
        $this->context = $context;
    }

    public function run(array $params): string
    {
        if (!$this->shouldBlockBeDisplayed($params)) {
            return '';
        }

        $this->assignTemplateVariables($params);

        return $this->module->fetch(
            'module:' . $this->module->name . '/views/templates/hook/' . $this->getTemplate()
        );
    }

    abstract protected function getTemplate(): string;

    abstract protected function assignTemplateVariables(array $params);

    protected function shouldBlockBeDisplayed(array $params)
    {
        return true;
    }
}

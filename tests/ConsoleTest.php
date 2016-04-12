<?php

use Dapphp\Konsoul\Konsoul;

define('PHPUNIT_TEST_RUN', 1);

class ConsoleTest extends PHPUnit_Framework_TestCase
{
    public $_testCommands = [
        "command_1",
        "command_2",
        "command_3",
        "another_command",
        "more_commands",
        "almost_last_command",
        "last_command",
    ];

    public function testSimpleCommandHistory()
    {
        $c = new Konsoul();

        foreach($this->_testCommands as $command) {
            $c->_addHistory($command);
        }

        $this->assertEquals($this->_testCommands, $c->getHistory());
    }

    public function testSaveRestoreCommandHistory()
    {
        $c = new Konsoul();

        foreach($this->_testCommands as $command) {
            $c->_addHistory($command);
        }

        $c->storeHistory();

        $commands = [ 'first', 'second', 'third', 'fourth' ];

        foreach($commands as $command) {
            $c->_addHistory($command);
        }

        $this->assertEquals($commands, $c->getHistory());

        $c->saveHistoryAs('test');
        $c->restoreHistory();

        $this->assertEquals($this->_testCommands, $c->getHistory());

        $c->storeHistory();
        $c->restoreHistory('test');

        $this->assertEquals($commands, $c->getHistory());

        $c->restoreHistory();

        $command = 'and another command';
        $temp    = $this->_testCommands;
        $temp[]  = $command;

        $c->_addHistory($command);

        $this->assertEquals($temp, $c->getHistory());
    }
}

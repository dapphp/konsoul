<?php

namespace Dapphp\Konsoul;

class Konsoul
{
    const OPT_REQUIRED = ':';
    const OPT_OPTIONAL = '::';
    const OPT_FLAG     = '';

    const SIGNAL_SIGHUP  = 1;
    const SIGNAL_SIGINT  = 2;
    const SIGNAL_SIGQUIT = 3;
    const SIGNAL_SIGKILL = 4;
    const SIGNAL_SIGUSR1 = 5;
    const SIGNAL_SIGUSR2 = 6;

    const ESC_BEGIN    = "\033[";
    const ESC_END      = "\033[0m";
    const ESC_CLEAR    = 0;
    const ESC_BOLD     = 1;
    const ESC_REVERSE  = 7;

    const COLOR_BLACK   = 0;
    const COLOR_RED     = 1;
    const COLOR_GREEN   = 2;
    const COLOR_YELLOW  = 3;
    const COLOR_BLUE    = 4;
    const COLOR_MAGENTA = 5;
    const COLOR_CYAN    = 6;
    const COLOR_WHITE   = 7;

    protected $_allowUnknownOptions = false;

    private $_args         = [];
    private $_argsUnparsed = [];
    private $_history      = [];
    private $_savedHistory = [];
    private $_savedHistoryStack = [];

    public function run($main)
    {
        if ('cli' != php_sapi_name()) {
            die("Program can only be run from the command line!\n");
        } elseif (!is_callable($main)) {
            throw new \Exception("Invalid callback supplied to run()");
        }

        $options = $this->parseCommandLineArguments();
        $this->processCommandLineArguments($options);
        $this->_argsUnparsed = $this->processUnparsedArguments($options);

        if (!$this->_allowUnknownOptions) {
            $prefix = '-';

            foreach($this->_argsUnparsed as $key => $val) {
                if (is_string($key)) {
                    if (strlen($key) > 1) $prefix .= '-';

                    echo sprintf('Unknown option "%s"' . "\n\n", $prefix . $key);
                    $this->usage();
                    exit(1);
                }
            }
        }

        try {
            $return = $main($options);
        } catch (\Exception $ex) {
            echo "Unhandled exception throw in main(): " . $ex->getMessage() . "\n";
            exit(255);
        }

        exit($return);
    }

    public function quit($code)
    {
        exit($code);
    }

    public function getScreenSize()
    {
        $x = $y = 0;

        if (function_exists('ncurses_getmaxyx')) {
            ncurses_getmaxyx(STDSCR, $y, $x);
        } elseif(stripos(PHP_OS, 'WIN') !== false) {
            // windows?
        } else {
            $x = `tput cols`;
            $y = `tput rows`;
        }

        return [ $x, $y ];
    }

    public function escape()
    {
        $this->_escapeSequence = [ self::ESC_BEGIN ];

        return $this;
    }

    public function clearEscape()
    {
        $this->_escapeSequence = [];

        echo self::ESC_BEGIN . self::ESC_CLEAR . 'm';

        return $this;
    }

    public function setReverse()
    {
        $this->_escapeSequence[] = self::ESC_REVERSE;

        return $this;
    }

    public function setBold()
    {
        $this->_escapeSequence[] = self::ESC_BOLD;

        return $this;
    }

    public function setForegroundColor($color)
    {
        $color = (int)$color;

        if ($color < 0 || $color > 7) {
            $color = 0;
        }

        $this->_escapeSequence[] = 90 + $color;

        return $this;
    }

    public function setBackgroundColor($color)
    {
        $color = (int)$color;

        if ($color < 0 || $color > 7) $color = 0;

        $this->_escapeSequence[] = 100 + $color;

        return $this;
    }

    public function eraseLine()
    {
        echo self::ESC_BEGIN . '2K';
    }

    public function resetScreen()
    {
        $this->clearScreen()
             ->setCursorPosition(1, 1);

        return $this;
    }

    public function clearScreen()
    {
        echo self::ESC_BEGIN . '2J';

        return $this;
    }

    public function setCursorPosition($x, $y)
    {
        echo self::ESC_BEGIN . "{$x};{$y}H";

        return $this;
    }

    public function output()
    {
        echo array_shift($this->_escapeSequence);

        $count = sizeof($this->_escapeSequence);
        for ($i = 0; $i < $count; ++$i) {
            echo array_shift($this->_escapeSequence);

            if ($count - $i > 1) {
                echo ';';
            }
        }
        echo 'm';
    }

    public function timeout($seconds)
    {
        if (!preg_match('/^\d+$/', $seconds) || (int)$seconds < 1) {
            throw new \Exception("Timeout must be an integer greater than 0");
        }

        $seconds  = (int)$seconds;
        $readline = (function_exists('readline')) ? true : false;
        $called   = false;

        do {
            echo sprintf("Waiting for %d seconds, press %s key to continue...", $seconds, ($readline ? 'any' : 'ENTER'));

            if ($readline) {
                readline_callback_handler_install('', $cb = function($ret) use (&$called) {
                    $called = true;
                });
            }
            // use stream_select to block STDIN for 1 second per call
            $n = stream_select($r = [STDIN], $w = null, $e = null, 1);

            // if STDIN changed - a key (or newline) was pressed
            if ($n && in_array(STDIN, $r)) {
                if ($readline) {
                    readline_callback_read_char();
                } else {
                    fgets(STDIN);
                }
                break;
            }

            $seconds--;
            $this->eraseLine(); echo "\r";
        } while ($seconds > 0);

        if ($readline) {
            readline_callback_handler_remove();
            if (!$called) {
                // a key other than "ENTER" was pressed to interrupt - output a newline
                echo "\n";
            }
        }

        return $this;
    }

    public function readLine($prompt = '')
    {
        if (function_exists('readline')) {
            $line = false;
            $done = false;

            readline_callback_handler_install($prompt . ' ', $cb = function($l) use(&$done, &$line) {
                // got line
                if ($l) $line = $l;
                $done = true;
            });

            while(true) {
                if ($done) {
                    readline_callback_handler_remove();
                    if ($line) {
                        $this->addHistory($line);
                    } else {
                        echo "\n";
                    }
                    break;
                } else {
                    // supress warning - with pcntl a signal may cause steam select to fail
                    $n = @stream_select($r = [STDIN], $w = null, $e = null, 0, 500000);
                    if ($n && in_array(STDIN, $r)) {
                        readline_callback_read_char();
                    }
                }
            }
        } else {
            do {
                if ($prompt) {
                    echo $prompt . ' ';
                }

                do {
                    $line    = false;
                    $read    = array(STDIN);
                    $write   = null;
                    $except  = null;
                    $changed = stream_select($read, $write, $except, 0, 250000);

                    if ($changed === false) {
                        $line = null;
                        break;
                    } elseif ($changed > 0) {
                        $char = fgetc(STDIN);
                        if ($char !== false) {
                            if ($char == "\r" || $char == "\n") {
                                $line = trim($char); // newline only
                            } else {
                                $data = fgets(STDIN);

                                if ($data === false) {
                                    $line = $char;
                                } else {
                                    $line = trim($char . $data);
                                }
                            }
                        }
                        break;
                    }
                } while (true); // stdin loop
            } while ($line !== null && $line !== false && $line == '');
        }

        return $line;
    }

    public function menu(array $options)
    {
        $i = 1;
        foreach($options as $option) {
            if (is_array($option)) {
                $option = array_shift($option);
            }
            echo sprintf("  %-3s %s\n", $i . '.', $option);
            $i++;
        }

        $i -= 1;

        do {
            $selection = $this->readLine("Enter selection [1-{$i}]:");

            if ($selection === null || $selection === false) {
                return null;
            }
        } while (!preg_match('/^\d+$/', $selection) || (int)$selection < 1 || (int)$selection > $i);

        $selection = (int)$selection;

        if (is_array($options[$selection - 1]) && is_callable($options[$selection - 1][1])) {
            $options[$selection - 1][1]();
        }

        return $selection;
    }

    public function getArgument($name, $default = null)
    {
        if (isset($this->_args[$name]) && isset($this->_args[$name]['value'])) {
            return $this->_args[$name]['value'];
        } elseif ($default !== null) {
            return $default;
        } else {
            return null;
        }
    }

    public function getArguments()
    {
        return $this->_args;
    }

    public function getUnparsedArguments()
    {
        return $this->_argsUnparsed;
    }

    public function addArgument($name, $type, $required = false, $validator = null, $callback = null, $description = '')
    {
        if (!in_array($type, [ self::OPT_REQUIRED, self::OPT_OPTIONAL, self::OPT_FLAG ])) {
            // throw exception - invalid type
        }

        $short  = $long = '';
        $params = [];

        // parse arg
        if (strpos($name, '|') !== false) {
            list($short, $long) = explode('|', $name, 2);
        } elseif (strlen($name) > 1) {
            $long = $name;
        } else {
            $short = $name;
        }

        if (!is_null($validator)) {
            if (is_callable($validator)) {
                $params['validator'] = $validator;
            } else {
                trigger_error("Invalid validator callback for {$name} param", E_USER_WARNING);
            }
        }

        if (!is_null($callback)) {
            if (is_callable($callback)) {
                $params['callback'] = $callback;
            } else {
                trigger_error("Invalid callback supplied for {$name} param", E_USER_WARNING);
            }
        }

        $params['description'] = $description;
        $params['type']        = $type;
        $params['required']    = $required;

        if (!empty($short)) {
            $params['short_option'] = $short . $type;
            $params['short_name']   = $short;
            $this->_args[$short]    = &$params;
        }
        if (!empty($long)) {
            $params['long_option']  = $long . $type;
            $params['long_name']    = $long;
            $this->_args[$long]     = &$params;
        }

        return $this;
    }

    public function usage()
    {
        $script = $_SERVER['argv'][0];
        $usage  = (in_array('Dapphp\Konsoul\Usage', class_uses($this))) ? true : false;

        if ($usage) {
            $this->usageHeader();
        }

        echo "Usage: {$script}\n";

        if ($usage) {
            $this->usageExamples($script);
        }

        foreach($this->_args as $opt => &$params) {
            $short = $long = $line = '';

            if (isset($params['displayed'])) continue;

            if (isset($params['short_option'])) {
                $short .= "-{$params['short_name']}";
                if (isset($params['long_option'])) {
                    $short .= ", ";
                }
            }

            if (isset($params['long_option'])) {
                $long .= "--{$params['long_name']}";
            }

            if ($params['type'] == self::OPT_OPTIONAL) {
                $line .= '[';
            }
            if ($params['type'] == self::OPT_OPTIONAL || $params['type'] == self::OPT_REQUIRED) {
                $line .= '=VALUE';
            }
            if ($params['type'] == self::OPT_OPTIONAL) {
                $line .= ']';
            }

            $long .= $line;

            $params['displayed'] = true;

            echo sprintf("  %-4s%-16s  %s\n", $short, $long, $params['description']);
        }

        if ($usage) {
            $this->usageFooter();
        }
    }

    public function clearHistory()
    {
        $this->_history = [];
        readline_clear_history();

        return $this;
    }

    public function saveHistoryAs($name)
    {
        $this->_savedHistory[$name] = $this->_history;

        return $this;
    }

    public function storeHistory()
    {
        $history = $this->_history;
        readline_clear_history();

        $this->_history             = [];
        $this->_savedHistoryStack[] = $history;

        return $this;
    }

    public function restoreHistory($name = null)
    {
        $history = null;

        if (!empty($name)) {
            if (isset($this->_savedHistory[$name])) {
                $history = $this->_savedHistory[$name];
            }
        } elseif (sizeof($this->_savedHistoryStack) > 0) {
            $history = array_pop($this->_savedHistoryStack);
        }

        if (!is_null($history)) {
            readline_clear_history();

            $this->_history = $history;

            foreach($history as $line) {
                readline_add_history($line);
            }
        }

        return $this;
    }

    public function getHistory()
    {
        return $this->_history;
    }

    public function setSignalHandler($signal, $handler)
    {
        if (!extension_loaded('pcntl')) return ;

        if (!is_callable($handler)) {
            throw new \Exception('Signal handler is not callable');
        }

        switch($signal) {
            case self::SIGNAL_SIGHUP:
                $s = SIGHUP; break;

            case self::SIGNAL_SIGINT:
                $s = SIGINT; break;

            case self::SIGNAL_SIGKILL:
                $s = SIGKILL; break;

            case self::SIGNAL_SIGQUIT:
                $s = SIGQUIT; break;

            case self::SIGNAL_SIGUSR1:
                $s = SIGUSR1; break;

            case self::SIGNAL_SIGUSR2:
                $s = SIGUSR2; break;

            default:
                trigger_error("Unrecognized signal {$signal}", E_USER_WARNING);
                break;
        }

        if (!pcntl_signal($s, $handler)) {
            trigger_error("Failed to register signal handler for signal {$signal}", E_USER_WARNING);
        }

        return $this;
    }

    public function addHistory($line)
    {
        // cannot depend on readline_list_history being defined - keep our own history
        $this->_history[] = $line;

        readline_add_history($line);

        return $this;
    }

    private function parseCommandLineArguments()
    {
        $shortopts = '';
        $longopts  = [];

        foreach($this->_args as $opt => $params) {
            if (isset($params['short_option'])) {
                $shortopts .= $params['short_option'];
            }
            if (isset($params['long_option'])) {
                $longopts[] = $params['long_option'];
            }
        }

        if (!empty($shortopts) || !empty($longopts)) {
            $options = getopt($shortopts, $longopts);
        } else {
            $options = [];
        }

        return $options;
    }

    private function processCommandLineArguments($arguments)
    {
        foreach($arguments as $opt => $val) {
            $params = $this->_args[$opt];

            if (isset($params['long_option']) && isset($params['short_option'])) {
                if (isset($arguments[$params['long_name']]) && isset($arguments[$params['short_name']])) {
                    // values for both long & short options were supplied at the same time
                    if ($params['type'] != self::OPT_FLAG) {
                        $this->usage();
                        exit(2);
                    }
                }
            }

            if (isset($params['validator']) && is_callable($params['validator'])) {
                $valid = $params['validator']($val); // function pointer

                if ($valid !== true) {
                    echo "invalid value for '{$opt}'\n\n";
                    $this->usage();
                    exit(2);
                }
            }

            if (isset($params['callback']) && is_callable($params['callback'])) {
                $params['callback']($val); // function pointer
            }

            $this->_args[$opt]['value'] = $val;
        }
    }

    private function processUnparsedArguments($options)
    {
        $argv    = $GLOBALS['argv'];
        $argc    = $GLOBALS['argc'];
        $newargv = [];

        // compare $argv to options parsed by getopt()
        for ($i = 1; $i < $argc; ++$i) {
            if (substr($argv[$i], 0, 2) == '--') {
                // long option
                $opt = substr($argv[$i], 2);
            } elseif (substr($argv[$i], 0, 1) == '-') {
                // short option
                $opt = substr($argv[$i], 1, 1);
            } else {
                // option argument
                $opt = null;
            }

            if (!isset($options[$opt])) {
                // $argv[$i] (without -|--) does not exist in options
                // append to newargv (as it is unparsed)
                $newargv[] = $argv[$i];
            } elseif (!is_bool($options[$opt]) && isset($argv[$i + 1]) && $options[$opt] == $argv[$i + 1]) {
                // $argv[$i] was parsed by getopt, and $argv[$i + 1] is its value
                // skip next arg
                $i++;
            } elseif (is_array($options[$opt])) {
                // same opt passed multiple times
                continue;
            } elseif (strpos($argv[$i], $options[$opt]) === 1) {
                // short arg with no space and a value (e.g. -xVALUE)
                continue;
            }
        }

        $otheroptions = []; // array of unparsed options

        for($i = 0; $i < sizeof($newargv); ++$i) {
            $arg = $newargv[$i];

            if (substr($arg, 0, 2) == '--') {
                // long option
                $opt = substr($arg, 2);
                if (strpos($opt, '=') !== false) {
                    list($opt, $value) = explode('=', $opt, 2);
                    $otheroptions[$opt] = $value;
                } else {
                    $otheroptions[$opt] = false;
                }
            } else if (substr($arg, 0, 1) == '-') {
                // short option
                $opt = substr($arg, 1, 1);
                $val = substr($arg, 2);
                $otheroptions[$opt] = false;

                if (!empty($val)) {
                    $otheroptions[$opt] = $val;
                }
            } else {
                // non-option
                $otheroptions[] = $arg;
            }
        }

        return $otheroptions;
    }
}

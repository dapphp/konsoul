<?php

namespace Dapphp\Konsoul;

trait Usage {
    /**
     * Header message to show above help screen.
     *
     * @return void
     */
    public function usageHeader() { }

    /**
     * Examples, or message to show right below "Usage: script.php" line and
     * above options list
     *
     * @return void
     */
    public function usageExamples($scriptName) { }

    /**
     * Message displayed after options.  Useful for copyright, contact info, or
     * other general information about the program.
     *
     * @return void
     */
    public function usageFooter() { }
}

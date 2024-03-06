<?php

namespace Prosetta\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

class MarkdownSanitizer {
    public function sanitize(string $markdown): string {
        $config = HTMLPurifier_Config::createDefault();

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($markdown);
    }
}

<?php
declare(strict_types=1);

namespace vielhuber\codechecker;

enum Status: string
{
    case Passed = 'passed';
    case Findings = 'findings';
    case Unavailable = 'unavailable';
    case Error = 'error';
    case Timeout = 'timeout';
    case NotApplicable = 'not_applicable';
}

<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vipertecpro\MobileEntitlements\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in(__DIR__.'/Feature');

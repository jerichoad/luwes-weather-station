<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('aggregates:refresh')->everyMinute()->withoutOverlapping();

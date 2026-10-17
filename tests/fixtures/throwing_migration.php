<?php

use Winter\Storm\Database\Updates\Migration;

/*
 * A migration that fails part-way, after Storm's Updater has turned off mass-assignment protection.
 */
return new class extends Migration
{
    public function up()
    {
        throw new RuntimeException('The migration failed part-way.');
    }

    public function down()
    {
    }
};

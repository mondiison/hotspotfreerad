<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nas')) {
            Schema::create('nas', function (Blueprint $table) {
                $table->id();
                $table->string('nasname', 128)->unique();
                $table->string('shortname', 32)->nullable();
                $table->string('type', 30)->default('other');
                $table->integer('ports')->nullable();
                $table->string('secret', 60);
                $table->string('server', 64)->nullable();
                $table->string('community', 50)->nullable();
                $table->string('description', 200)->nullable();
            });
        }

        if (! Schema::hasTable('radcheck')) {
            Schema::create('radcheck', function (Blueprint $table) {
                $table->id();
                $table->string('username', 64)->index();
                $table->string('attribute', 64);
                $table->string('op', 2)->default('==');
                $table->string('value', 253);
            });
        }

        if (! Schema::hasTable('radreply')) {
            Schema::create('radreply', function (Blueprint $table) {
                $table->id();
                $table->string('username', 64)->index();
                $table->string('attribute', 64);
                $table->string('op', 2)->default('=');
                $table->string('value', 253);
            });
        }

        if (! Schema::hasTable('radusergroup')) {
            Schema::create('radusergroup', function (Blueprint $table) {
                $table->string('username', 64);
                $table->string('groupname', 64);
                $table->integer('priority')->default(1);

                $table->index('username');
            });
        }

        if (! Schema::hasTable('radgroupcheck')) {
            Schema::create('radgroupcheck', function (Blueprint $table) {
                $table->id();
                $table->string('groupname', 64)->index();
                $table->string('attribute', 64);
                $table->string('op', 2)->default('==');
                $table->string('value', 253);
            });
        }

        if (! Schema::hasTable('radgroupreply')) {
            Schema::create('radgroupreply', function (Blueprint $table) {
                $table->id();
                $table->string('groupname', 64)->index();
                $table->string('attribute', 64);
                $table->string('op', 2)->default('=');
                $table->string('value', 253);
            });
        }

        if (! Schema::hasTable('radacct')) {
            Schema::create('radacct', function (Blueprint $table) {
                $table->id('radacctid');
                $table->string('acctsessionid', 64)->index();
                $table->string('acctuniqueid', 32)->unique();
                $table->string('username', 64)->index();
                $table->string('realm', 64)->nullable();
                $table->string('nasipaddress', 15)->index();
                $table->string('nasportid', 32)->nullable();
                $table->string('nasporttype', 32)->nullable();
                $table->dateTime('acctstarttime')->nullable()->index();
                $table->dateTime('acctupdatetime')->nullable();
                $table->dateTime('acctstoptime')->nullable()->index();
                $table->integer('acctinterval')->nullable();
                $table->bigInteger('acctsessiontime')->nullable();
                $table->string('acctauthentic', 32)->nullable();
                $table->string('connectinfo_start', 50)->nullable();
                $table->string('connectinfo_stop', 50)->nullable();
                $table->bigInteger('acctinputoctets')->nullable();
                $table->bigInteger('acctoutputoctets')->nullable();
                $table->string('calledstationid', 50)->nullable();
                $table->string('callingstationid', 50)->nullable();
                $table->string('acctterminatecause', 32)->nullable();
                $table->string('servicetype', 32)->nullable();
                $table->string('framedprotocol', 32)->nullable();
                $table->string('framedipaddress', 15)->nullable()->index();
                $table->string('framedipv6address', 45)->nullable();
                $table->string('framedipv6prefix', 45)->nullable();
                $table->string('framedinterfaceid', 44)->nullable();
                $table->string('delegatedipv6prefix', 45)->nullable();
            });
        }

        if (! Schema::hasTable('radpostauth')) {
            Schema::create('radpostauth', function (Blueprint $table) {
                $table->id();
                $table->string('username', 64)->index();
                $table->string('pass', 64);
                $table->string('reply', 32);
                $table->timestamp('authdate')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['radpostauth', 'radacct', 'radgroupreply', 'radgroupcheck', 'radusergroup', 'radreply', 'radcheck', 'nas'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

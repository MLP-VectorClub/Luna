<?php

use App\Utils\AppearanceIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The color guide search lives in Postgres now (it used to be an OpenSearch index): one `tsvector` per official appearance,
 * made of its label, tags and tag synonyms, searched by prefix. Winterchilla reads and writes the same table
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('appearance_search', function (Blueprint $table) {
            $table->foreignId('appearance_id')->primary()->constrained('appearances')->onDelete('cascade')->onUpdate('cascade');
        });
        DB::statement(/** @lang PostgreSQL */ 'ALTER TABLE appearance_search ADD COLUMN doc tsvector NOT NULL');
        DB::statement(/** @lang PostgreSQL */ 'CREATE INDEX appearance_search_doc_idx ON appearance_search USING gin (doc)');

        AppearanceIndex::rebuild();
    }

    public function down()
    {
        Schema::dropIfExists('appearance_search');
    }
};

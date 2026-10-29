<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the 'sessions' schema for CC Session Manager integration.
     * 8 tables migrated from SQLite, plus session_summaries for archiving.
     */
    public function up(): void
    {
        // Drop existing schema (idempotent for migrate:fresh on Postgres)
        DB::statement('DROP SCHEMA IF EXISTS sessions CASCADE');

        // Create schema
        DB::statement('CREATE SCHEMA IF NOT EXISTS sessions');

        // 1. projects — CC Session Manager's project records (discovered from ~/.claude/projects/)
        DB::statement('
            CREATE TABLE sessions.projects (
                id SERIAL PRIMARY KEY,
                encoded_path TEXT NOT NULL UNIQUE,
                decoded_path TEXT NOT NULL,
                display_name TEXT NOT NULL,
                is_pinned BOOLEAN DEFAULT false,
                is_archived BOOLEAN DEFAULT false,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL
            )
        ');
        DB::statement('CREATE INDEX idx_ccprojects_encoded ON sessions.projects(encoded_path)');
        DB::statement('CREATE INDEX idx_ccprojects_pinned ON sessions.projects(is_pinned, updated_at DESC)');

        // 2. sessions — One row per JSONL transcript file
        DB::statement('
            CREATE TABLE sessions.sessions (
                id SERIAL PRIMARY KEY,
                session_id TEXT NOT NULL UNIQUE,
                project_id INT NOT NULL REFERENCES sessions.projects(id) ON DELETE CASCADE,
                file_path TEXT NOT NULL UNIQUE,
                file_size INT NOT NULL,
                file_mtime BIGINT NOT NULL,
                first_prompt TEXT,
                first_prompt_timestamp TIMESTAMPTZ,
                last_activity_timestamp TIMESTAMPTZ,
                git_branch TEXT,
                cwd TEXT,
                claude_version TEXT,
                message_count INT DEFAULT 0,
                turn_count INT DEFAULT 0,
                has_subagents BOOLEAN DEFAULT false,
                total_input_tokens INT DEFAULT 0,
                total_output_tokens INT DEFAULT 0,
                total_cache_creation_tokens INT DEFAULT 0,
                total_cache_read_tokens INT DEFAULT 0,
                estimated_cost_usd NUMERIC(10, 4) DEFAULT 0,
                fork_count INT DEFAULT 0,
                is_starred BOOLEAN DEFAULT false,
                is_archived BOOLEAN DEFAULT false,
                notes TEXT,
                indexed_at TIMESTAMPTZ NOT NULL
            )
        ');
        DB::statement('CREATE INDEX idx_sessions_project ON sessions.sessions(project_id, last_activity_timestamp DESC)');
        DB::statement('CREATE INDEX idx_sessions_activity ON sessions.sessions(last_activity_timestamp DESC)');
        DB::statement('CREATE INDEX idx_sessions_starred ON sessions.sessions(is_starred, last_activity_timestamp DESC)');
        DB::statement('CREATE INDEX idx_sessions_branch ON sessions.sessions(git_branch, last_activity_timestamp DESC)');
        DB::statement('CREATE INDEX idx_sessions_mtime ON sessions.sessions(file_mtime)');
        DB::statement('CREATE INDEX idx_sessions_cost ON sessions.sessions(estimated_cost_usd DESC)');

        // 3. messages — Individual messages from transcripts
        DB::statement('
            CREATE TABLE sessions.messages (
                id SERIAL PRIMARY KEY,
                session_id TEXT NOT NULL REFERENCES sessions.sessions(session_id) ON DELETE CASCADE,
                message_uuid TEXT UNIQUE NOT NULL,
                parent_uuid TEXT,
                message_type TEXT NOT NULL CHECK (message_type IN (\'user\', \'assistant\')),
                timestamp TIMESTAMPTZ NOT NULL,
                content_text TEXT,
                model TEXT,
                input_tokens INT,
                output_tokens INT,
                cache_creation_tokens INT,
                cache_read_tokens INT,
                tool_names TEXT[],
                is_sidechain BOOLEAN DEFAULT false,
                agent_id TEXT,
                sequence_number INT NOT NULL,
                search_vector tsvector
            )
        ');
        DB::statement('CREATE INDEX idx_messages_session ON sessions.messages(session_id, sequence_number)');
        DB::statement('CREATE INDEX idx_messages_parent ON sessions.messages(parent_uuid)');
        DB::statement('CREATE INDEX idx_messages_model ON sessions.messages(model)');
        DB::statement('CREATE INDEX idx_messages_fts ON sessions.messages USING GIN(search_vector)');

        // Auto-update search_vector on insert/update
        DB::statement("
            CREATE TRIGGER messages_search_vector_update
            BEFORE INSERT OR UPDATE ON sessions.messages
            FOR EACH ROW EXECUTE FUNCTION
            tsvector_update_trigger(search_vector, 'pg_catalog.english', content_text)
        ");

        // 4. tags — User-created tags
        DB::statement('
            CREATE TABLE sessions.tags (
                id SERIAL PRIMARY KEY,
                name TEXT UNIQUE NOT NULL,
                color TEXT,
                created_at TIMESTAMPTZ NOT NULL
            )
        ');

        // 5. session_tags — Many-to-many junction
        DB::statement('
            CREATE TABLE sessions.session_tags (
                session_id TEXT NOT NULL REFERENCES sessions.sessions(session_id) ON DELETE CASCADE,
                tag_id INT NOT NULL REFERENCES sessions.tags(id) ON DELETE CASCADE,
                created_at TIMESTAMPTZ NOT NULL,
                PRIMARY KEY (session_id, tag_id)
            )
        ');
        DB::statement('CREATE INDEX idx_session_tags_tag ON sessions.session_tags(tag_id)');

        // 6. model_pricing — Token pricing for cost calculation
        DB::statement('
            CREATE TABLE sessions.model_pricing (
                model TEXT PRIMARY KEY,
                input_token_price NUMERIC(10, 6) NOT NULL,
                output_token_price NUMERIC(10, 6) NOT NULL,
                cache_creation_price NUMERIC(10, 6),
                cache_read_price NUMERIC(10, 6),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');

        // Seed model pricing (prices are $ per 1M tokens)
        DB::statement("
            INSERT INTO sessions.model_pricing (model, input_token_price, output_token_price, cache_creation_price, cache_read_price) VALUES
            ('claude-sonnet-4-5-20250929', 3.00, 15.00, 3.75, 0.30),
            ('claude-opus-4-5-20251101', 15.00, 75.00, 18.75, 1.50),
            ('claude-opus-4-6', 15.00, 75.00, 18.75, 1.50),
            ('claude-haiku-4-5-20251001', 1.00, 5.00, 1.25, 0.10)
        ");

        // 7. scan_history — Audit trail for file scanner runs
        DB::statement('
            CREATE TABLE sessions.scan_history (
                id SERIAL PRIMARY KEY,
                started_at TIMESTAMPTZ NOT NULL,
                completed_at TIMESTAMPTZ,
                status TEXT NOT NULL CHECK (status IN (\'running\', \'completed\', \'failed\')),
                files_scanned INT DEFAULT 0,
                files_added INT DEFAULT 0,
                files_updated INT DEFAULT 0,
                files_removed INT DEFAULT 0,
                error_message TEXT
            )
        ');

        // 8. session_summaries — Long-term aggregates for archiving
        DB::statement('
            CREATE TABLE sessions.session_summaries (
                session_id TEXT PRIMARY KEY,
                project_slug TEXT,
                started_at TIMESTAMPTZ,
                ended_at TIMESTAMPTZ,
                message_count INT,
                turn_count INT,
                total_input_tokens INT,
                total_output_tokens INT,
                total_cache_creation_tokens INT,
                total_cache_read_tokens INT,
                estimated_cost_usd NUMERIC(10, 4),
                models_used TEXT[],
                top_tools TEXT[],
                duration_minutes INT,
                first_prompt TEXT,
                git_branch TEXT,
                archived_at TIMESTAMPTZ DEFAULT NOW()
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS sessions CASCADE');
    }
};

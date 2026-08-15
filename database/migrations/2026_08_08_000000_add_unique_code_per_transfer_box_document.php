<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_transfer_box_document_duplicate_code()
                RETURNS trigger AS $function$
                BEGIN
                    IF EXISTS (
                        SELECT 1
                        FROM transfer_box_documents
                        WHERE transfer_box_id = NEW.transfer_box_id
                          AND lower(trim(code)) = lower(trim(NEW.code))
                          AND id <> COALESCE(NEW.id, 0)
                    ) THEN
                        RAISE EXCEPTION 'El codigo del documento ya existe dentro de esta caja.';
                    END IF;

                    RETURN NEW;
                END;
                $function$ LANGUAGE plpgsql;

                CREATE TRIGGER transfer_box_documents_box_code_unique
                BEFORE INSERT OR UPDATE OF transfer_box_id, code ON transfer_box_documents
                FOR EACH ROW
                EXECUTE FUNCTION prevent_transfer_box_document_duplicate_code();
            SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER transfer_box_documents_box_code_unique_insert
            BEFORE INSERT ON transfer_box_documents
            FOR EACH ROW
            WHEN EXISTS (
                SELECT 1
                FROM transfer_box_documents
                WHERE transfer_box_id = NEW.transfer_box_id
                  AND lower(trim(code)) = lower(trim(NEW.code))
            )
            BEGIN
                SELECT RAISE(ABORT, 'El codigo del documento ya existe dentro de esta caja.');
            END;

            CREATE TRIGGER transfer_box_documents_box_code_unique_update
            BEFORE UPDATE OF transfer_box_id, code ON transfer_box_documents
            FOR EACH ROW
            WHEN EXISTS (
                SELECT 1
                FROM transfer_box_documents
                WHERE transfer_box_id = NEW.transfer_box_id
                  AND lower(trim(code)) = lower(trim(NEW.code))
                  AND id <> OLD.id
            )
            BEGIN
                SELECT RAISE(ABORT, 'El codigo del documento ya existe dentro de esta caja.');
            END;
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS transfer_box_documents_box_code_unique ON transfer_box_documents');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_transfer_box_document_duplicate_code()');

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS transfer_box_documents_box_code_unique_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS transfer_box_documents_box_code_unique_update');
    }
};

<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026\Tests;

use PDO;

/**
 * Synthetic fixture only. Never uses config.php, .env or remote DB.
 * Intentional shared username SYNTH_A across TW/JP tests cross-server IDOR.
 */
final class FixtureData
{
    public const SCHEMA_FINGERPRINT = '8de93e52c187059f924be7ffa9c6755767f3fe4bc2d104cc097098de6bd7cd34';

    public static function sessionA(array $overrides=[]): array
    {
        return array_replace([
            'steam_id' => '76561190000000000', 'user_id' => 12,
            'username' => 'SYNTH_A', 'user_region' => 'TW',
        ], $overrides);
    }

    public static function sessionB(array $overrides=[]): array
    {
        return array_replace([
            'steam_id' => '76561190000000001', 'user_id' => 13,
            'username' => 'SYNTH_A', 'user_region' => 'JP',
        ], $overrides);
    }

    public static function manifest(array $overrides=[]): array
    {
        return array_replace([
            'issuer' => 'CHECKPOINT2026_SYNTHETIC_FIXTURE',
            'mode' => 'SYNTHETIC_STAGING',
            'schema_fingerprint' => self::SCHEMA_FINGERPRINT,
            'source_watermark' => '2026-11-24 10:00:00',
            'start_at' => '2026-01-01 00:00:00',
            'observed_rows' => 4,
            'expected_rows' => 4,
            'missing_slices' => 0,
            'integrity' => 'PASS',
            'snapshot_revision' => 'M2-fixture-v1',
        ], $overrides);
    }

    public static function pdo(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec(<<<'SQL'
CREATE TABLE game_user (
  id INTEGER PRIMARY KEY,
  steamID TEXT UNIQUE,
  username TEXT NOT NULL,
  region TEXT NOT NULL,
  ack INTEGER, apply INTEGER, black_list INTEGER
);
CREATE TABLE arena_unlight (
  id INTEGER PRIMARY KEY,
  region TEXT,
  ack1 INTEGER, ack2 INTEGER,
  win INTEGER, lose INTEGER, tie INTEGER,
  update_time TEXT, name_p1 TEXT, name_p2 TEXT
);
CREATE TABLE arena_player_match_result (
  id INTEGER PRIMARY KEY,
  match_id INTEGER NOT NULL,
  side TEXT NOT NULL,
  player_name TEXT NOT NULL,
  update_time TEXT NOT NULL,
  leader_id INTEGER, back1_id INTEGER, back2_id INTEGER,
  team_key TEXT, cost INTEGER, is_win INTEGER,
  UNIQUE(match_id,side)
);
CREATE TABLE tournament_reports (
  id INTEGER PRIMARY KEY,tournament_id INTEGER,
  issue_key TEXT,report_title TEXT,status TEXT,published_at TEXT
);
CREATE TABLE tournament_report_sections (
  id INTEGER PRIMARY KEY,report_id INTEGER,section_key TEXT,
  section_title TEXT,sort_order INTEGER,is_visible INTEGER
);
CREATE TABLE tournament_matches (
  id INTEGER PRIMARY KEY,tournament_id INTEGER,status TEXT,
  player1_id INTEGER,player2_id INTEGER,winner_player_id INTEGER,updated_at TEXT
);
CREATE TABLE tournament_match_games (
  id INTEGER PRIMARY KEY,tournament_id INTEGER,match_id INTEGER,game_no INTEGER,
  result_status TEXT,player1_id INTEGER,player2_id INTEGER,
  winner_player_id INTEGER,created_at TEXT,updated_at TEXT
);
SQL);
        $db->exec(<<<'SQL'
INSERT INTO game_user VALUES
 (12,'76561190000000000','SYNTH_A','TW',1,0,0),
 (13,'76561190000000001','SYNTH_A','JP',1,0,0),
 (14,'76561190000000002','OTHER','TW',0,1,0);
INSERT INTO arena_unlight VALUES
 (1,'TW',1,1,0,1,0,'2026-06-01 08:00:00','SYNTH_A','SYNTH_OPP'),
 (2,'TW',1,1,0,1,0,'2026-06-03 08:00:00','SYNTH_OPP','SYNTH_A'),
 (3,'TW',1,1,0,0,1,'2026-06-05 08:00:00','SYNTH_A','SYNTH_OPP'),
 (4,'MATCH',1,1,0,1,0,'2026-06-07 08:00:00','SYNTH_A','SYNTH_OPP'),
 (5,'JP',1,1,0,1,0,'2026-06-09 08:00:00','SYNTH_A','SYNTH_OPP'),
 (6,'TW',1,0,0,1,0,'2026-06-11 08:00:00','SYNTH_A','SYNTH_OPP'),
 (7,'TW',1,1,0,1,0,'2026-12-01 08:00:00','SYNTH_A','SYNTH_OPP');
INSERT INTO arena_player_match_result VALUES
 (1,1,'P1','SYNTH_A','2026-06-01 08:00:00',11,22,33,'11-22-33',20,1),
 (2,2,'P2','SYNTH_A','2026-06-03 08:00:00',11,33,22,'11-22-33',20,0),
 (3,3,'P1','SYNTH_A','2026-06-05 08:00:00',11,22,33,'11-22-33',20,NULL),
 (4,4,'P1','SYNTH_A','2026-06-07 08:00:00',11,22,33,'11-22-33',20,1),
 (5,5,'P1','SYNTH_A','2026-06-09 08:00:00',11,22,33,'11-22-33',20,1),
 (6,6,'P1','SYNTH_A','2026-06-11 08:00:00',11,22,33,'11-22-33',20,1),
 (7,7,'P1','SYNTH_A','2026-12-01 08:00:00',11,22,33,'11-22-33',20,1);
INSERT INTO tournament_reports VALUES
 (1,1,'final','SYNTH CUP Final','published','2026-07-21 10:00:00'),
 (2,1,'draft','SECRET REPORT','draft',NULL),
 (3,2,'future','FUTURE REPORT','published','2027-01-02 10:00:00');
INSERT INTO tournament_report_sections VALUES
 (1,1,'story','Public synthetic story',1,1),
 (2,1,'secret','PRIVATE INTERNAL',2,0),
 (3,2,'draft','DRAFT PRIVATE',1,1);
INSERT INTO tournament_matches VALUES
 (1,1,'confirmed',101,102,101,'2026-07-20 08:00:00'),
 (2,1,'pending',101,102,NULL,'2026-07-20 08:00:00'),
 (3,1,'finished',101,102,102,'2026-07-20 08:00:00'),
 (4,2,'confirmed',201,202,201,'2026-07-20 08:00:00');
INSERT INTO tournament_match_games VALUES
 (10,1,1,1,'verified',101,102,101,'2026-07-20 08:00:00','2026-07-20 08:00:00'),
 (11,1,1,2,'candidate',101,102,102,'2026-07-20 08:00:00','2026-07-20 08:00:00'),
 (12,1,2,1,'verified',101,102,101,'2026-07-20 08:00:00','2026-07-20 08:00:00'),
 (13,1,3,1,'verified',101,102,999,'2026-07-20 08:00:00','2026-07-20 08:00:00'),
 (14,1,3,2,'verified',101,102,102,'2026-07-20 08:00:00','2026-07-20 08:00:00'),
 (15,2,4,1,'verified',201,202,201,'2026-07-20 08:00:00','2026-07-20 08:00:00');
SQL);
        return $db;
    }
}

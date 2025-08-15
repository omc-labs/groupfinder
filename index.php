<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Group Finder (PHP + SQLite) — 1..850 + turma — cPanel-friendly (?api=...)

// Roteamento via ?api=...
$method = $_SERVER['REQUEST_METHOD'];
$path   = isset($_GET['api']) ? '/api/' . ltrim((string)$_GET['api'], '/')
                              : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// DB
$dbPath = __DIR__ . DIRECTORY_SEPARATOR . 'data.sqlite';
try {
  $db = new SQLite3($dbPath);
  if (method_exists($db, 'enableExceptions')) $db->enableExceptions(true);
  // $db->exec('PRAGMA journal_mode = WAL'); // evite WAL em shared
  $db->exec('PRAGMA busy_timeout = 3000');
} catch (Throwable $e) {
  http_response_code(500);
  echo 'DB_FAIL: ' . $e->getMessage();
  exit;
}

/* ===== Helpers de schema ===== */
function tableExists(SQLite3 $db, string $name): bool {
  $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:n");
  $stmt->bindValue(':n', $name, SQLITE3_TEXT);
  $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  return $r && isset($r['name']);
}
function createSql(SQLite3 $db, string $name): ?string {
  $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=:n");
  $stmt->bindValue(':n', $name, SQLITE3_TEXT);
  $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  return $r ? ($r['sql'] ?? null) : null;
}
function tableHasColumn(SQLite3 $db, string $table, string $col): bool {
  $res = $db->query("PRAGMA table_info(" . $table . ")");
  while ($c = $res->fetchArray(SQLITE3_ASSOC)) {
    if (isset($c['name']) && $c['name'] === $col) return true;
  }
  return false;
}
function rescueFromOld(SQLite3 $db) {
  // Se existir *_old de migração anterior, finalize agora
  $hasGroupsOld  = tableExists($db, 'groups_old');
  $hasEntriesOld = tableExists($db, 'entries_old');

  if (!$hasGroupsOld && !$hasEntriesOld) return;

  $db->exec('BEGIN');
  try {
    if ($hasGroupsOld) {
      if (tableExists($db, 'groups')) $db->exec('DROP TABLE groups');
      $db->exec("CREATE TABLE groups (id INTEGER PRIMARY KEY CHECK (id BETWEEN 1 AND 850))");
      $db->exec("INSERT INTO groups (id) SELECT id FROM groups_old WHERE id BETWEEN 1 AND 850");
      $db->exec("DROP TABLE groups_old");
    }
    if ($hasEntriesOld) {
      if (tableExists($db, 'entries')) $db->exec('DROP TABLE entries');
      $db->exec("CREATE TABLE entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NOT NULL CHECK (group_id BETWEEN 1 AND 850),
        turma TEXT NOT NULL CHECK (turma IN ('001','002')) DEFAULT '001',
        name TEXT NOT NULL,
        whatsapp TEXT NOT NULL,
        linkedin TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      )");
      // Detecta se entries_old tem coluna turma
      $hasTurmaOld = tableHasColumn($db, 'entries_old', 'turma');
      if ($hasTurmaOld) {
        $db->exec("INSERT INTO entries (id, group_id, turma, name, whatsapp, linkedin, created_at)
                   SELECT id, group_id, turma, name, whatsapp, linkedin, created_at
                   FROM entries_old WHERE group_id BETWEEN 1 AND 850");
      } else {
        $db->exec("INSERT INTO entries (id, group_id, turma, name, whatsapp, linkedin, created_at)
                   SELECT id, group_id, '001' as turma, name, whatsapp, linkedin, created_at
                   FROM entries_old WHERE group_id BETWEEN 1 AND 850");
      }
      $db->exec("DROP TABLE entries_old");
    }
    $db->exec('COMMIT');
  } catch (Throwable $e) {
    $db->exec('ROLLBACK');
    http_response_code(500);
    echo 'MIGRATION_RESCUE_FAIL: ' . $e->getMessage();
    exit;
  }
}
function migrateIfNeeded(SQLite3 $db) {
  // Finaliza migrações pendentes primeiro
  rescueFromOld($db);

  $groupsSql  = createSql($db, 'groups');
  $entriesSql = createSql($db, 'entries');

  $groupsNeedsRange = $groupsSql && strpos($groupsSql, 'BETWEEN 1 AND 800') !== false;
  $entriesExists    = !!$entriesSql;
  $entriesNeedsRange= $entriesSql && strpos($entriesSql, 'BETWEEN 1 AND 800') !== false;
  $entriesHasTurma  = $entriesSql ? tableHasColumn($db, 'entries', 'turma') : false;

  $needEntriesRebuild = false;
  if (!$entriesExists) {
    // criar do zero com turma + 850
    $db->exec("CREATE TABLE entries (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      group_id INTEGER NOT NULL CHECK (group_id BETWEEN 1 AND 850),
      turma TEXT NOT NULL CHECK (turma IN ('001','002')) DEFAULT '001',
      name TEXT NOT NULL,
      whatsapp TEXT NOT NULL,
      linkedin TEXT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
  } else {
    // precisa reconstruir se range antigo ou sem turma
    if ($entriesNeedsRange || !$entriesHasTurma) $needEntriesRebuild = true;
  }

  $db->exec('BEGIN');
  try {
    // groups: range antigo -> recria
    if ($groupsNeedsRange) {
      $db->exec("ALTER TABLE groups RENAME TO groups_old");
      $db->exec("CREATE TABLE groups (id INTEGER PRIMARY KEY CHECK (id BETWEEN 1 AND 850))");
      $db->exec("INSERT INTO groups (id) SELECT id FROM groups_old WHERE id BETWEEN 1 AND 850");
      $db->exec("DROP TABLE groups_old");
    } elseif (!$groupsSql) {
      // criar se não existe
      $db->exec("CREATE TABLE groups (id INTEGER PRIMARY KEY CHECK (id BETWEEN 1 AND 850))");
    }

    // entries: rebuild se necessário
    if ($needEntriesRebuild) {
      $db->exec("ALTER TABLE entries RENAME TO entries_old");
      $db->exec("CREATE TABLE entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NOT NULL CHECK (group_id BETWEEN 1 AND 850),
        turma TEXT NOT NULL CHECK (turma IN ('001','002')) DEFAULT '001',
        name TEXT NOT NULL,
        whatsapp TEXT NOT NULL,
        linkedin TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      )");
      $hasTurmaOld = tableHasColumn($db, 'entries_old', 'turma');
      if ($hasTurmaOld) {
        $db->exec("INSERT INTO entries (id, group_id, turma, name, whatsapp, linkedin, created_at)
                   SELECT id, group_id, turma, name, whatsapp, linkedin, created_at
                   FROM entries_old WHERE group_id BETWEEN 1 AND 850");
      } else {
        $db->exec("INSERT INTO entries (id, group_id, turma, name, whatsapp, linkedin, created_at)
                   SELECT id, group_id, '001' as turma, name, whatsapp, linkedin, created_at
                   FROM entries_old WHERE group_id BETWEEN 1 AND 850");
      }
      $db->exec("DROP TABLE entries_old");
    }

    $db->exec('COMMIT');
  } catch (Throwable $e) {
    $db->exec('ROLLBACK');
    http_response_code(500);
    echo 'MIGRATION_FAIL: ' . $e->getMessage();
    exit;
  }

  // Seed 1..850 nos groups
  $row = $db->query('SELECT COUNT(*) AS c FROM groups')->fetchArray(SQLITE3_ASSOC);
  $count = (int)($row['c'] ?? 0);
  if ($count < 850) {
    $db->exec('BEGIN');
    try {
      $stmt = $db->prepare('INSERT OR IGNORE INTO groups (id) VALUES (:id)');
      for ($i = 1; $i <= 850; $i++) {
        $stmt->bindValue(':id', $i, SQLITE3_INTEGER);
        $stmt->execute();
      }
      $db->exec('COMMIT');
    } catch (Throwable $e) {
      $db->exec('ROLLBACK');
      http_response_code(500);
      echo 'SEED_FAIL: ' . $e->getMessage();
      exit;
    }
  }
}

// Executa migração segura
migrateIfNeeded($db);

/* ===== Helpers gerais ===== */
function jsonOut($data, int $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function sanitize_whatsapp($s) {
  $v = preg_replace('/[^\d\+]/', '', $s ?? '');
  return mb_substr($v, 0, 20);
}
function is_valid_linkedin($url) {
  if (!$url) return false;
  $parts = parse_url($url);
  if (!$parts || !isset($parts['host'])) return false;
  $host = strtolower($parts['host']);
  return $host === 'linkedin.com' || preg_match('/(^|\.)linkedin\.com$/', $host);
}
function normalize_turma($t) {
  $t = trim((string)$t);
  return in_array($t, ['001','002'], true) ? $t : null;
}

/* ===== API ===== */
if (strpos($path, '/api/') === 0) {

  if ($path === '/api/health') {
    jsonOut(['ok' => true]);
  }

  // /api/groups
  if ($path === '/api/groups' && $method === 'GET') {
    $sql = 'SELECT g.id, COALESCE(cnt.c, 0) AS count
            FROM groups g
            LEFT JOIN (SELECT group_id, COUNT(*) AS c FROM entries GROUP BY group_id) cnt
              ON cnt.group_id = g.id
            ORDER BY g.id ASC';
    $rows = [];
    $res = $db->query($sql);
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    jsonOut($rows);
  }

  // /api/groups/{id}
  if (preg_match('#^/api/groups/(\d+)$#', $path, $m)) {
    $gid = (int)$m[1];
    if ($gid < 1 || $gid > 850) jsonOut(['error' => 'Invalid group id'], 400);

    if ($method === 'GET') {
      $turma = isset($_GET['turma']) ? normalize_turma($_GET['turma']) : null;
      if ($turma) {
        $stmt = $db->prepare('SELECT id, :gid AS group_id, turma, name, whatsapp, linkedin, created_at
                              FROM entries WHERE group_id = :gid AND turma = :turma
                              ORDER BY datetime(created_at) DESC, id DESC');
        $stmt->bindValue(':gid', $gid, SQLITE3_INTEGER);
        $stmt->bindValue(':turma', $turma, SQLITE3_TEXT);
      } else {
        $stmt = $db->prepare('SELECT id, :gid AS group_id, turma, name, whatsapp, linkedin, created_at
                              FROM entries WHERE group_id = :gid
                              ORDER BY datetime(created_at) DESC, id DESC');
        $stmt->bindValue(':gid', $gid, SQLITE3_INTEGER);
      }
      $res  = $stmt->execute();
      $rows = [];
      while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
      jsonOut($rows);
    }

    if ($method === 'POST') {
      $raw      = file_get_contents('php://input');
      $dataIn   = json_decode($raw, true);
      $name     = trim($dataIn['name'] ?? '');
      $whatsapp = sanitize_whatsapp($dataIn['whatsapp'] ?? '');
      $linkedin = trim($dataIn['linkedin'] ?? '');
      $turma    = normalize_turma($dataIn['turma'] ?? '');

      if ($name === '' || $whatsapp === '' || $linkedin === '' || !$turma)
        jsonOut(['error' => 'Missing fields (name, whatsapp, linkedin, turma)'], 400);
      if (mb_strlen($name) > 80) jsonOut(['error' => 'Name too long'], 400);
      if (mb_strlen($linkedin) > 200) jsonOut(['error' => 'LinkedIn URL too long'], 400);
      if (!is_valid_linkedin($linkedin)) jsonOut(['error' => 'LinkedIn URL must be linkedin.com'], 400);

      $stmt = $db->prepare('INSERT INTO entries (group_id, turma, name, whatsapp, linkedin)
                            VALUES (:gid, :turma, :n, :w, :l)');
      $stmt->bindValue(':gid',   $gid, SQLITE3_INTEGER);
      $stmt->bindValue(':turma', $turma, SQLITE3_TEXT);
      $stmt->bindValue(':n',     $name, SQLITE3_TEXT);
      $stmt->bindValue(':w',     $whatsapp, SQLITE3_TEXT);
      $stmt->bindValue(':l',     $linkedin, SQLITE3_TEXT);
      $ok = $stmt->execute();
      if ($ok) jsonOut(['ok' => true, 'id' => $db->lastInsertRowID()]);
      jsonOut(['error' => 'DB error'], 500);
    }

    jsonOut(['error' => 'Method not allowed'], 405);
  }

  // /api/search?q=...
  if ($path === '/api/search' && $method === 'GET') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') jsonOut([]);

    if (preg_match('/^\d{1,3}$/', $q) && (int)$q >= 1 && (int)$q <= 850) {
      $gid  = (int)$q;
      $stmt = $db->prepare('SELECT id, group_id, turma, name, whatsapp, linkedin, created_at
                            FROM entries WHERE group_id = :gid
                            ORDER BY datetime(created_at) DESC, id DESC LIMIT 200');
      $stmt->bindValue(':gid', $gid, SQLITE3_INTEGER);
      $res  = $stmt->execute();
      $rows = [];
      while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
      jsonOut($rows);
    }

    $like = '%' . $q . '%';
    $stmt = $db->prepare('SELECT id, group_id, turma, name, whatsapp, linkedin, created_at
                          FROM entries
                          WHERE name LIKE :lk OR whatsapp LIKE :lk OR linkedin LIKE :lk
                          ORDER BY datetime(created_at) DESC, id DESC LIMIT 200');
    $stmt->bindValue(':lk', $like, SQLITE3_TEXT);
    $res  = $stmt->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    jsonOut($rows);
  }

  jsonOut(['error' => 'Not found'], 404);
}

/* ===== SPA/static fallback ===== */
$pathInfo = $path === '/' ? '/index.html' : $path;
$file = __DIR__ . $pathInfo;
if (strpos(realpath($file) ?: '', realpath(__DIR__)) !== 0) { http_response_code(403); echo 'Forbidden'; exit; }
if (is_file($file)) {
  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  $types = [
    'html'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8',
    'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml','ico'=>'image/x-icon','json'=>'application/json; charset=utf-8',
  ];
  header('Content-Type', $types[$ext] ?? 'application/octet-stream');
  readfile($file); exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');

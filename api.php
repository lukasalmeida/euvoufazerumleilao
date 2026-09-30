<?php
declare(strict_types=1);

const BID_TURN_SECONDS = 5;

class DuplicateItemsException extends DomainException
{
    public function __construct(public readonly array $names)
    {
        parent::__construct('Há itens repetidos ou já cadastrados nesta sala. Escolha outros nomes.');
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $directory = dirname(__DIR__) . '/.' . basename(__DIR__) . '-data';
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $directory . '/auction.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        code TEXT PRIMARY KEY, theme TEXT NOT NULL DEFAULT '', starting_credits INTEGER NOT NULL,
        phase TEXT NOT NULL DEFAULT 'lobby', host_id TEXT NOT NULL, current_item_id INTEGER,
        created_at INTEGER NOT NULL, seller_receives_credits INTEGER NOT NULL DEFAULT 1
    )");
    $roomColumns = $pdo->query('PRAGMA table_info(rooms)')->fetchAll();
    if (!in_array('seller_receives_credits', array_column($roomColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE rooms ADD COLUMN seller_receives_credits INTEGER NOT NULL DEFAULT 1');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id TEXT PRIMARY KEY, room_code TEXT NOT NULL REFERENCES rooms(code) ON DELETE CASCADE,
        name TEXT NOT NULL, credits INTEGER NOT NULL, joined_at INTEGER NOT NULL
    )");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS player_names_per_room ON players(room_code, name COLLATE NOCASE)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, room_code TEXT NOT NULL REFERENCES rooms(code) ON DELETE CASCADE,
        owner_id TEXT NOT NULL REFERENCES players(id), name TEXT NOT NULL, minimum_bid INTEGER NOT NULL,
        position INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'draft', current_bid INTEGER NOT NULL DEFAULT 0,
        buyer_id TEXT REFERENCES players(id), deadline INTEGER, turn_player_id TEXT
    )");
    $itemColumns = $pdo->query('PRAGMA table_info(items)')->fetchAll();
    if (!in_array('turn_player_id', array_column($itemColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE items ADD COLUMN turn_player_id TEXT');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS bids (
        id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER NOT NULL REFERENCES items(id),
        player_id TEXT NOT NULL REFERENCES players(id), amount INTEGER NOT NULL, created_at INTEGER NOT NULL
    )");
    return $pdo;
}

function requestData(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function normalizeItemName(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
}

function requiredString(array $data, string $key, int $maxLength): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '' || textLength($value) > $maxLength) {
        throw new DomainException("Informe um valor válido para {$key}.");
    }
    return $value;
}

function roomAndPlayer(PDO $pdo, array $data): array
{
    $code = strtoupper(trim((string)($data['roomCode'] ?? '')));
    $playerId = (string)($data['playerId'] ?? '');
    $statement = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $statement->execute([$code]);
    $room = $statement->fetch();
    $statement = $pdo->prepare('SELECT * FROM players WHERE id = ? AND room_code = ?');
    $statement->execute([$playerId, $code]);
    $player = $statement->fetch();
    if (!$room || !$player) {
        throw new DomainException('Essa sala não foi encontrada para este jogador.');
    }
    return [$room, $player];
}

function begin(PDO $pdo): void
{
    $pdo->exec('BEGIN IMMEDIATE');
}

function completeCurrentItem(PDO $pdo, array &$room, array $item): void
{
    if ($item['buyer_id']) {
        $statement = $pdo->prepare('UPDATE players SET credits = credits - ? WHERE id = ?');
        $statement->execute([$item['current_bid'], $item['buyer_id']]);
        if ((int)$room['seller_receives_credits'] === 1 && $item['owner_id'] !== $item['buyer_id']) {
            $statement = $pdo->prepare('UPDATE players SET credits = credits + ? WHERE id = ?');
            $statement->execute([$item['current_bid'], $item['owner_id']]);
        }
        $statement = $pdo->prepare("UPDATE items SET status = 'sold' WHERE id = ?");
    } else {
        $statement = $pdo->prepare("UPDATE items SET status = 'unsold' WHERE id = ?");
    }
    $statement->execute([$item['id']]);

    $statement = $pdo->prepare("SELECT id FROM items WHERE room_code = ? AND status = 'queued' ORDER BY position LIMIT 1");
    $statement->execute([$room['code']]);
    $next = $statement->fetchColumn();
    if ($next) {
        $statement = $pdo->prepare('SELECT id FROM players WHERE room_code = ? ORDER BY joined_at, name LIMIT 1');
        $statement->execute([$room['code']]);
        $firstPlayer = $statement->fetchColumn();
        $statement = $pdo->prepare("UPDATE items SET status = 'active', deadline = ?, turn_player_id = ? WHERE id = ?");
        $statement->execute([time() + BID_TURN_SECONDS, $firstPlayer, $next]);
        $statement = $pdo->prepare('UPDATE rooms SET current_item_id = ? WHERE code = ?');
        $statement->execute([$next, $room['code']]);
        $room['current_item_id'] = $next;
    } else {
        $statement = $pdo->prepare("UPDATE rooms SET phase = 'finished', current_item_id = NULL WHERE code = ?");
        $statement->execute([$room['code']]);
        $room['phase'] = 'finished';
        $room['current_item_id'] = null;
    }
}

function finishExpiredAuction(PDO $pdo, array &$room): void
{
    if ($room['phase'] !== 'auction' || !$room['current_item_id']) {
        return;
    }
    $statement = $pdo->prepare("SELECT * FROM items WHERE id = ? AND status = 'active'");
    $statement->execute([$room['current_item_id']]);
    $item = $statement->fetch();
    if (!$item || (int)$item['deadline'] > time()) {
        return;
    }

    $statement = $pdo->prepare('SELECT id FROM players WHERE room_code = ? ORDER BY joined_at, name');
    $statement->execute([$room['code']]);
    $playerIds = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (!$playerIds) {
        completeCurrentItem($pdo, $room, $item);
        return;
    }
    $currentIndex = array_search($item['turn_player_id'], $playerIds, true);
    $nextIndex = (($currentIndex === false ? -1 : $currentIndex) + 1) % count($playerIds);
    $nextPlayerId = $playerIds[$nextIndex];
    $queueFinished = $item['buyer_id']
        ? $nextPlayerId === $item['buyer_id']
        : $nextIndex === 0;

    if ($queueFinished) {
        completeCurrentItem($pdo, $room, $item);
        return;
    }

    $statement = $pdo->prepare('UPDATE items SET turn_player_id = ?, deadline = ? WHERE id = ?');
    $statement->execute([$nextPlayerId, time() + BID_TURN_SECONDS, $item['id']]);
}

function state(PDO $pdo, array $data): array
{
    [$room, $player] = roomAndPlayer($pdo, $data);
    begin($pdo);
    try {
        $statement = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
        $statement->execute([$room['code']]);
        $room = $statement->fetch();
        finishExpiredAuction($pdo, $room);
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        $pdo->exec('ROLLBACK');
        throw $error;
    }

    $statement = $pdo->prepare('SELECT id, name, credits, joined_at FROM players WHERE room_code = ? ORDER BY joined_at, name');
    $statement->execute([$room['code']]);
    $players = $statement->fetchAll();
    $statement = $pdo->prepare("SELECT i.id, i.name, i.minimum_bid, i.position, i.status, i.current_bid, i.buyer_id, i.deadline, i.turn_player_id,
        owner.name AS owner_name, buyer.name AS buyer_name
        FROM items i JOIN players owner ON owner.id = i.owner_id
        LEFT JOIN players buyer ON buyer.id = i.buyer_id
        WHERE i.room_code = ? ORDER BY i.position");
    $statement->execute([$room['code']]);
    $items = $statement->fetchAll();
    $itemCount = [];
    foreach ($items as $item) {
        $itemCount[$item['owner_name']] = ($itemCount[$item['owner_name']] ?? 0) + 1;
    }
    foreach ($players as &$entry) {
        $entry['item_count'] = $itemCount[$entry['name']] ?? 0;
        $entry['is_host'] = $entry['id'] === $room['host_id'];
        $entry['is_me'] = $entry['id'] === $player['id'];
    }
    unset($entry);

    $currentItem = null;
    if ($room['current_item_id']) {
        foreach ($items as $item) {
            if ((int)$item['id'] === (int)$room['current_item_id']) {
                $currentItem = $item;
                break;
            }
        }
    }
    return [
        'room' => ['code' => $room['code'], 'theme' => $room['theme'], 'startingCredits' => (int)$room['starting_credits'], 'phase' => $room['phase'], 'hostId' => $room['host_id'], 'sellerReceivesCredits' => (bool)$room['seller_receives_credits']],
        'me' => ['id' => $player['id'], 'name' => $player['name'], 'credits' => (int)$player['credits'], 'isHost' => $player['id'] === $room['host_id']],
        'players' => $players,
        'items' => $items,
        'currentItem' => $currentItem,
    ];
}

try {
    $pdo = database();
    $data = requestData();
    $action = (string)($data['action'] ?? $_GET['action'] ?? '');

    if ($action === 'create') {
        $name = requiredString($data, 'name', 24);
        $credits = filter_var($data['credits'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 20, 'max_range' => 100000]]);
        if ($credits === false) {
            throw new DomainException('Os créditos devem ficar entre 20 e 100.000.');
        }
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
            $statement = $pdo->prepare('SELECT 1 FROM rooms WHERE code = ?');
            $statement->execute([$code]);
        } while ($statement->fetchColumn());
        $playerId = bin2hex(random_bytes(16));
        $statement = $pdo->prepare('INSERT INTO rooms (code, starting_credits, host_id, created_at) VALUES (?, ?, ?, ?)');
        $statement->execute([$code, $credits, $playerId, time()]);
        $statement = $pdo->prepare('INSERT INTO players (id, room_code, name, credits, joined_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$playerId, $code, $name, $credits, time()]);
        respond(['ok' => true, 'roomCode' => $code, 'playerId' => $playerId]);
    }

    if ($action === 'join') {
        $name = requiredString($data, 'name', 24);
        $code = strtoupper(requiredString($data, 'roomCode', 6));
        $statement = $pdo->prepare("SELECT * FROM rooms WHERE code = ? AND phase IN ('lobby', 'items')");
        $statement->execute([$code]);
        $room = $statement->fetch();
        if (!$room) {
            throw new DomainException('Sala indisponível. Confira o código ou veja se o leilão já começou.');
        }
        $playerId = bin2hex(random_bytes(16));
        $statement = $pdo->prepare('INSERT INTO players (id, room_code, name, credits, joined_at) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([$playerId, $code, $name, $room['starting_credits'], time()]);
        respond(['ok' => true, 'roomCode' => $code, 'playerId' => $playerId]);
    }

    [$room, $player] = roomAndPlayer($pdo, $data);
    if ($action === 'state') {
        respond(['ok' => true, 'state' => state($pdo, $data)]);
    }

    if ($action === 'theme') {
        if ($player['id'] !== $room['host_id'] || $room['phase'] !== 'lobby') {
            throw new DomainException('Somente o anfitrião pode definir o tema nesta etapa.');
        }
        $theme = requiredString($data, 'theme', 60);
        $statement = $pdo->prepare('UPDATE rooms SET theme = ? WHERE code = ?');
        $statement->execute([$theme, $room['code']]);
        respond(['ok' => true]);
    }

    if ($action === 'seller_credit_mode') {
        if ($player['id'] !== $room['host_id'] || $room['phase'] !== 'lobby') {
            throw new DomainException('Somente o anfitrião pode alterar essa opção no lobby.');
        }
        $enabled = filter_var($data['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new DomainException('Informe se quem vende deve receber os créditos.');
        }
        $statement = $pdo->prepare('UPDATE rooms SET seller_receives_credits = ? WHERE code = ?');
        $statement->execute([$enabled ? 1 : 0, $room['code']]);
        respond(['ok' => true]);
    }

    if ($action === 'open_items') {
        if ($player['id'] !== $room['host_id'] || $room['phase'] !== 'lobby' || trim($room['theme']) === '') {
            throw new DomainException('Defina um tema antes de abrir o cadastro dos itens.');
        }
        $statement = $pdo->prepare("UPDATE rooms SET phase = 'items' WHERE code = ?");
        $statement->execute([$room['code']]);
        respond(['ok' => true]);
    }

    if ($action === 'save_items') {
        if ($room['phase'] !== 'items') {
            throw new DomainException('O cadastro de itens não está aberto.');
        }
        $items = $data['items'] ?? null;
        if (!is_array($items) || count($items) !== 5) {
            throw new DomainException('Cadastre exatamente cinco itens.');
        }
        $cleanItems = [];
        $submittedNames = [];
        $duplicateNames = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new DomainException('Confira os dados dos cinco itens.');
            }
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '' || textLength($name) > 60) {
                throw new DomainException('Cada item precisa de um nome válido.');
            }
            $cleanItems[] = $name;
            $normalizedName = normalizeItemName($name);
            if (isset($submittedNames[$normalizedName])) {
                $duplicateNames[$normalizedName] = $name;
            } else {
                $submittedNames[$normalizedName] = $name;
            }
        }
        if ($duplicateNames) {
            throw new DuplicateItemsException(array_values($duplicateNames));
        }
        begin($pdo);
        try {
            $statement = $pdo->prepare("SELECT name FROM items WHERE room_code = ? AND NOT (owner_id = ? AND status = 'draft')");
            $statement->execute([$room['code'], $player['id']]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $existingName) {
                $normalizedName = normalizeItemName((string)$existingName);
                if (isset($submittedNames[$normalizedName])) {
                    $duplicateNames[$normalizedName] = $submittedNames[$normalizedName];
                }
            }
            if ($duplicateNames) {
                throw new DuplicateItemsException(array_values($duplicateNames));
            }
            $statement = $pdo->prepare("SELECT COUNT(*) FROM items WHERE room_code = ? AND owner_id = ? AND status = 'draft'");
            $statement->execute([$room['code'], $player['id']]);
            if ((int)$statement->fetchColumn() > 0) {
                $statement = $pdo->prepare("DELETE FROM items WHERE room_code = ? AND owner_id = ? AND status = 'draft'");
                $statement->execute([$room['code'], $player['id']]);
            }
            $statement = $pdo->prepare('INSERT INTO items (room_code, owner_id, name, minimum_bid, position) VALUES (?, ?, ?, 0, ?)');
            foreach ($cleanItems as $position => $name) {
                $statement->execute([$room['code'], $player['id'], $name, time() + random_int(0, 100000) + $position]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }
        respond(['ok' => true]);
    }

    if ($action === 'start_auction') {
        if ($player['id'] !== $room['host_id'] || $room['phase'] !== 'items') {
            throw new DomainException('Somente o anfitrião pode iniciar o leilão.');
        }
        begin($pdo);
        try {
            $statement = $pdo->prepare('SELECT p.name, COUNT(i.id) AS item_count FROM players p LEFT JOIN items i ON i.owner_id = p.id AND i.status = ? WHERE p.room_code = ? GROUP BY p.id');
            $statement->execute(['draft', $room['code']]);
            foreach ($statement->fetchAll() as $entry) {
                if ((int)$entry['item_count'] !== 5) {
                    throw new DomainException("Aguardando {$entry['name']} cadastrar os cinco itens.");
                }
            }
            $statement = $pdo->prepare('SELECT id FROM items WHERE room_code = ? ORDER BY position');
            $statement->execute([$room['code']]);
            $itemIds = array_column($statement->fetchAll(), 'id');
            if (!$itemIds) {
                throw new DomainException('Não há itens para leiloar.');
            }
            shuffle($itemIds);
            foreach ($itemIds as $position => $itemId) {
                $statement = $pdo->prepare("UPDATE items SET position = ?, status = 'queued' WHERE id = ?");
                $statement->execute([$position, $itemId]);
            }
            $firstItem = $itemIds[0];
            $statement = $pdo->prepare('SELECT id FROM players WHERE room_code = ? ORDER BY joined_at, name LIMIT 1');
            $statement->execute([$room['code']]);
            $firstPlayer = $statement->fetchColumn();
            $statement = $pdo->prepare("UPDATE items SET status = 'active', deadline = ?, turn_player_id = ? WHERE id = ?");
            $statement->execute([time() + BID_TURN_SECONDS, $firstPlayer, $firstItem]);
            $statement = $pdo->prepare("UPDATE rooms SET phase = 'auction', current_item_id = ? WHERE code = ?");
            $statement->execute([$firstItem, $room['code']]);
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }
        respond(['ok' => true]);
    }

    if ($action === 'bid') {
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($amount === false) {
            throw new DomainException('Informe um lance válido.');
        }
        $itemIdBeforeProgress = $room['current_item_id'];
        begin($pdo);
        try {
            $statement = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
            $statement->execute([$room['code']]);
            $room = $statement->fetch();
            finishExpiredAuction($pdo, $room);
            if ($room['phase'] !== 'auction' || !$room['current_item_id'] || $room['current_item_id'] !== $itemIdBeforeProgress) {
                throw new DomainException('Este lote encerrou. Atualize para ver o próximo item.');
            }
            $statement = $pdo->prepare("SELECT * FROM items WHERE id = ? AND status = 'active'");
            $statement->execute([$room['current_item_id']]);
            $item = $statement->fetch();
            if ($item['turn_player_id'] !== $player['id']) {
                throw new DomainException('Ainda não é a sua vez. Aguarde o próximo turno.');
            }
            $statement = $pdo->prepare('SELECT credits FROM players WHERE id = ?');
            $statement->execute([$player['id']]);
            $credits = (int)$statement->fetchColumn();
            $minimum = max(1, (int)$item['current_bid'] * ($item['buyer_id'] ? 2 : 1));
            if ($amount < $minimum) {
                throw new DomainException("O próximo lance precisa ser de pelo menos {$minimum} créditos.");
            }
            if ($credits < $amount) {
                throw new DomainException('Seu saldo não cobre esse lance.');
            }
            $statement = $pdo->prepare('SELECT id FROM players WHERE room_code = ? ORDER BY joined_at, name');
            $statement->execute([$room['code']]);
            $playerIds = $statement->fetchAll(PDO::FETCH_COLUMN);
            $playerIndex = array_search($player['id'], $playerIds, true);
            $nextPlayerId = $playerIds[($playerIndex + 1) % count($playerIds)];
            $statement = $pdo->prepare('UPDATE items SET current_bid = ?, buyer_id = ?, turn_player_id = ?, deadline = ? WHERE id = ?');
            $statement->execute([$amount, $player['id'], $nextPlayerId, time() + BID_TURN_SECONDS, $item['id']]);
            $statement = $pdo->prepare('INSERT INTO bids (item_id, player_id, amount, created_at) VALUES (?, ?, ?, ?)');
            $statement->execute([$item['id'], $player['id'], $amount, time()]);
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }
        respond(['ok' => true]);
    }

    if ($action === 'end_auction') {
        if ($player['id'] !== $room['host_id'] || $room['phase'] !== 'auction') {
            throw new DomainException('Somente o anfitrião pode encerrar o leilão em andamento.');
        }
        begin($pdo);
        try {
            $statement = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
            $statement->execute([$room['code']]);
            $activeRoom = $statement->fetch();
            if (!$activeRoom || $activeRoom['phase'] !== 'auction' || !$activeRoom['current_item_id']) {
                throw new DomainException('O leilão já foi encerrado. Atualize a sala.');
            }
            $statement = $pdo->prepare("SELECT * FROM items WHERE id = ? AND room_code = ? AND status = 'active'");
            $statement->execute([$activeRoom['current_item_id'], $room['code']]);
            $item = $statement->fetch();
            if ($item && $item['buyer_id']) {
                $statement = $pdo->prepare('UPDATE players SET credits = credits - ? WHERE id = ?');
                $statement->execute([$item['current_bid'], $item['buyer_id']]);
                if ((int)$activeRoom['seller_receives_credits'] === 1 && $item['owner_id'] !== $item['buyer_id']) {
                    $statement = $pdo->prepare('UPDATE players SET credits = credits + ? WHERE id = ?');
                    $statement->execute([$item['current_bid'], $item['owner_id']]);
                }
                $statement = $pdo->prepare("UPDATE items SET status = 'sold' WHERE id = ?");
                $statement->execute([$item['id']]);
            } elseif ($item) {
                $statement = $pdo->prepare("UPDATE items SET status = 'unsold' WHERE id = ?");
                $statement->execute([$item['id']]);
            }
            $statement = $pdo->prepare("UPDATE items SET status = 'unsold' WHERE room_code = ? AND status = 'queued'");
            $statement->execute([$room['code']]);
            $statement = $pdo->prepare("UPDATE rooms SET phase = 'finished', current_item_id = NULL WHERE code = ?");
            $statement->execute([$room['code']]);
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }
        respond(['ok' => true]);
    }

    if ($action === 'restart_session') {
        if ($player['id'] !== $room['host_id'] || $room['phase'] !== 'finished') {
            throw new DomainException('Somente o anfitrião pode reiniciar uma sessão encerrada.');
        }
        begin($pdo);
        try {
            $statement = $pdo->prepare('SELECT phase FROM rooms WHERE code = ?');
            $statement->execute([$room['code']]);
            if ($statement->fetchColumn() !== 'finished') {
                throw new DomainException('A sessão não está encerrada. Atualize a sala.');
            }
            $statement = $pdo->prepare('DELETE FROM bids WHERE item_id IN (SELECT id FROM items WHERE room_code = ?)');
            $statement->execute([$room['code']]);
            $statement = $pdo->prepare('DELETE FROM items WHERE room_code = ?');
            $statement->execute([$room['code']]);
            $statement = $pdo->prepare('UPDATE players SET credits = (SELECT starting_credits FROM rooms WHERE code = ?) WHERE room_code = ?');
            $statement->execute([$room['code'], $room['code']]);
            $statement = $pdo->prepare("UPDATE rooms SET phase = 'lobby', current_item_id = NULL WHERE code = ?");
            $statement->execute([$room['code']]);
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }
        respond(['ok' => true]);
    }

    throw new DomainException('Ação desconhecida.');
} catch (DuplicateItemsException $error) {
    respond(['ok' => false, 'error' => $error->getMessage(), 'duplicateItems' => $error->names], 400);
} catch (DomainException $error) {
    respond(['ok' => false, 'error' => $error->getMessage()], 400);
} catch (PDOException $error) {
    if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
        respond(['ok' => false, 'error' => 'Já existe alguém com esse nome na sala.'], 409);
    }
    error_log($error->getMessage());
    respond(['ok' => false, 'error' => 'Não foi possível concluir a operação. Tente novamente.'], 500);
} catch (Throwable $error) {
    error_log($error->getMessage());
    respond(['ok' => false, 'error' => 'Ocorreu um erro inesperado.'], 500);
}
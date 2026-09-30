# Contexto do Projeto: Leilão Multiplayer (PHP Local)

## 1. Visão Geral
Aplicação web em PHP executada em rede local (LAN) para um jogo interativo de leilão multiplayer em tempo real ou baseado em polling/turnos. Os jogadores entram com um nome, configuram parâmetros na sessão (como saldo inicial e tema), cadastram seus itens e participam da disputa dando lances para arrematar os objetos cadastrados.

---

## 2. Requisitos Funcionais

### 2.1. Home & Identificação
- Entrada do nome do usuário.
- Definição do saldo de créditos inicial por participante (ou valor padrão global definido pelo host).
- Criação ou entrada em uma sala/sessão existente na rede local.

### 2.2. Lobby & Configuração
- Exibição da lista de jogadores conectados na sala.
- Definição do **Tema do Leilão** pelo host/criador da sala (ex: "Carros Clássicos", "Artigos Geek", "Relíquias Antigas").
- Status de prontidão dos jogadores.

### 2.3. Cadastro de Itens
- Cada jogador cadastrará exatamente **5 itens** relacionados ao tema definido.
- Cada item deve conter:
  - Nome/Descrição do item.
- A fase de leilão só inicia quando todos os participantes finalizarem a inclusão dos 5 itens.

### 2.4. Leilão (Gameplay)
- Exibição sequencial dos itens cadastrados (sorteados ou ordenados por fila).
- Timer por rodada/item (opcional, mas recomendado para controle do tempo de lance).
- Todos os jogadores podem disputar os mesmos itens; o primeiro lance começa em 1 crédito e cada lance seguinte deve ser pelo menos o dobro do valor atual.
- Interface de lances:
  - Botões para dar lances superiores ao lance atual.
  - Validação do saldo disponível do jogador (não permitir lances superiores ao crédito restante).
  - Atualização do maior lance e do respectivo comprador atual.
- Encerramento do leilão do item atual e transferência do item/créditos.

### 2.5. Finalização & Resultado
- Exibição do inventário final arrematado por cada jogador.
- Exibição do saldo restante.
- Ranking do vencedor (baseado em saldo restante, quantidade de itens arrematados ou valor total do inventário).
- O anfitrião pode encerrar o leilão antes do fim e reiniciar a sessão, restaurando os créditos e reabrindo o cadastro dos itens.
- No lobby, o anfitrião escolhe se os créditos pagos pelo vencedor são repassados a quem cadastrou o item; se desativado, o valor é retirado sem acréscimo para outro jogador.
- O saldo inicial por jogador pode ser configurado a partir de 20 créditos.

---

## 3. Arquitetura Técnica & Stack Sugerida

- **Linguagem Principal:** PHP (sem dependência estrita de frameworks externos a princípio).
- **Banco de Dados / Armazenamento:**
  - SQLite ou MySQL/MariaDB (para persistência do estado da sala, jogadores, itens e lances).
  - Altamente recomendado para simplificar o controle concorrente em rede local.
- **Comunicação em Tempo Real / Polling:**
  - AJAX / Fetch API com Polling curto (requests a cada 1-2 segundos) para sincronizar o estado da sala, maior lance atual e tempo restante, evitando a necessidade de configurar servidores WebSocket complexos em ambiente PHP básico.
- **Frontend:**
  - HTML5, CSS3 e JavaScript puro (ES6+) para manipular os lances e chamadas de API assíncronas sem dar refresh na página.

---

## 4. Estrutura do Banco de Dados (Sugerida)

### `salas`
- `id` (INT, PK)
- `codigo` (VARCHAR)
- `tema` (VARCHAR)
- `credito_inicial` (DECIMAL/INT)
- `status` (ENUM: 'lobby', 'cadastro_itens', 'em_andamento', 'finalizado')
- `item_atual_id` (INT, FK)

### `jogadores`
- `id` (INT, PK)
- `sala_id` (INT, FK)
- `nome` (VARCHAR)
- `credito_atual` (DECIMAL/INT)
- `ip_address` (VARCHAR)

### `itens`
- `id` (INT, PK)
- `sala_id` (INT, FK)
- `jogador_dono_id` (INT, FK)
- `nome` (VARCHAR)
- `lance_minimo` (DECIMAL/INT)
- `lance_atual` (DECIMAL/INT)
- `comprador_id` (INT, FK, NULLABLE)
- `status` (ENUM: 'pendente', 'em_leilao', 'vendido')

### `lances`
- `id` (INT, PK)
- `item_id` (INT, FK)
- `jogador_id` (INT, FK)
- `valor` (DECIMAL/INT)
- `created_at` (TIMESTAMP)

---

## 5. Fluxo da Aplicação

```
[ Home ] -> Digita Nome e Créditos Inicial -> Entra ou Cria Sala
  |
[ Lobby ] -> Definição do Tema (Host) -> Aguarda Jogadores -> Iniciar
  |
[ Cadastro ] -> Cada jogador cadastra 5 itens -> Aguarda Todos
  |
[ Leilão Loop ] -> Exibe Item N -> Jogadores Dão Lances -> Fim do Tempo -> Registra Venda
  |
[ Resultado ] -> Exibe Inventário e Saldo de Todos os Jogadores
```
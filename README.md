# Eu vou fazer um leilão

Jogo multiplayer de leilão para uma rede local. Requer PHP 8.1+ com PDO SQLite habilitado; não há dependências externas de PHP. Os dados ficam em uma pasta oculta ao lado do projeto, fora da raiz pública do servidor.

## Executar na rede local

Na pasta do projeto, execute:

```sh
php -S 0.0.0.0:8000
```

No computador anfitrião, abra `http://localhost:8000`. Nos celulares conectados à mesma rede Wi-Fi, acesse `http://IP-DO-COMPUTADOR:8000` (por exemplo, `http://192.168.1.20:8000`). A liberação da porta 8000 no firewall pode ser necessária.

## Como jogar

1. O anfitrião cria a sala e define o saldo inicial por jogador (mínimo de 20 créditos).
2. Compartilhe o código da sala; cada participante entra com seu nome.
3. O anfitrião define o tema e abre o cadastro. Cada pessoa salva cinco itens, que ficam disponíveis para todos os jogadores disputarem.
4. No lobby, o anfitrião escolhe se os créditos dos lances serão repassados a quem cadastrou os itens. Quando todos estiverem prontos, inicia o leilão: o primeiro lance pode ser de 1 crédito e cada lance seguinte deve ser pelo menos o dobro do atual.
5. Cada lote fica aberto por 25 segundos. O maior lance vence o item; se o repasse estiver desligado, os créditos saem do vencedor sem serem adicionados ao saldo de quem cadastrou o item. O anfitrião também pode encerrar o leilão antes do tempo, mantendo o maior lance como vencedor do lote atual.
6. Ao final, o anfitrião pode reiniciar a sessão. Os jogadores e o tema são mantidos, os créditos são restaurados e os itens voltam a ser cadastrados do zero.

A tela sincroniza automaticamente a cada 1,5 segundo. A sala e os jogadores ficam salvos no SQLite local.
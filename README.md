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
2. No código da sala, abra o convite para copiar o código, compartilhar o link ou mostrar o QR code. O link usa o IP do servidor na rede; cada participante informa seu nome para entrar.
3. O anfitrião define o tema e abre o cadastro. Cada pessoa salva cinco itens, que ficam disponíveis para todos os jogadores disputarem.
4. No lobby, o anfitrião escolhe se os créditos dos lances serão repassados a quem cadastrou os itens. Quando todos estiverem prontos, inicia o leilão: o primeiro lance pode ser de 1 crédito e cada lance seguinte deve ser pelo menos o dobro do atual.
5. Os lances seguem a ordem de entrada na sala, um jogador por vez, com 5 segundos para cada turno. Depois de um lance, a fila continua entre os outros jogadores; se o maior lance continuar sem cobertura até a vez voltar a quem está na frente, essa pessoa arremata. Se ninguém der lance, o lote é encerrado sem venda. Com o repasse desligado, os créditos saem do vencedor sem serem adicionados ao saldo de quem cadastrou o item. O anfitrião também pode encerrar o leilão antes do fim, mantendo o maior lance como vencedor do lote atual.
6. Ao final, o anfitrião pode reiniciar a sessão. Os jogadores e o tema são mantidos, os créditos são restaurados e os itens voltam a ser cadastrados do zero.

A tela sincroniza automaticamente a cada 1,5 segundo. A sala e os jogadores ficam salvos no SQLite local.
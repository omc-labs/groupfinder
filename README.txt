# Group Finder (PHP + SQLite) — cPanel/HostGator
Este pacote é ideal para hospedagens compartilhadas (cPanel). Não precisa Node.js.

## Como instalar
1. Acesse o **cPanel** → **Gerenciador de Arquivos** e vá ao `public_html` (ou à pasta do subdomínio).
2. Envie este `.zip` e use **Extrair**.
3. Certifique-se de que o arquivo `data.sqlite` será criado automaticamente na primeira execução (precisa permissão de escrita na pasta).
4. Acesse a URL (ex.: `https://seu-dominio.com/`).

## Requisitos
- PHP 7.4+ com extensão `sqlite3` (normalmente já habilitada).
- Permissão de escrita no diretório do app.

## Estrutura
- `index.php`: roteador do app + API (REST).
- `.htaccess`: envia todas as rotas para `index.php` (SPA + API).
- `styles.css`, `script.js`, `index.html`: front-end.

## Rotas
- `GET /api/groups` — lista 1..800 com contador
- `GET /api/groups/{id}` — lista entradas do grupo
- `POST /api/groups/{id}` — adiciona entrada `{name, whatsapp, linkedin}` (JSON)
- `GET /api/search?q=...` — busca por nome/whats/linkedin ou número do grupo

Pronto!
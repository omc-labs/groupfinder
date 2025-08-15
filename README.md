# GroupFinder

Encontre facilmente colegas por **grupo** e **turma**.  
O app lista grupos **1–850**; cada pessoa informa **Nome**, **WhatsApp** e **LinkedIn**, seleciona a **Turma (001/002)** e pronto. Há **busca global** e **contador** de inscritos.

> Pensado para **hospedagem cPanel/HostGator**: **PHP + SQLite**, sem dependências externas.

---

## ✨ Funcionalidades

- **Grupos 1–850** com contador total por grupo
- **Turmas 001/002**: filtro no modal e escolha ao cadastrar
- **Busca global** por nome, WhatsApp, LinkedIn ou nº do grupo
- **Contador** de inscritos (total e por turma)
- **UI simples e responsiva** (HTML/CSS/JS puros)
- **Migrações automáticas** do banco (ex.: 800 → 850, inclusão de `turma`) sem perder dados
- **Compatível com cPanel** (API via `index.php?api=...`, sem exigir `.htaccess`)

---

## 🧱 Arquitetura & Stack

- **Frontend:** HTML + CSS + JavaScript (vanilla)
- **Backend:** PHP 7.4+ (recomendado PHP 8+)
- **Banco:** SQLite (arquivo `data.sqlite` ao lado do `index.php`)
- **Roteamento da API:** query string (`index.php?api=...`) — evita problemas de PATH_INFO/.htaccess em hosts compartilhados

**Estrutura:**

```
index.php        # API + roteador + migrações (1..850, turma)
index.html       # SPA
script.js        # lógica do front (usa index.php?api=...)
styles.css       # estilos
.htaccess        # opcional (não é obrigatório)
data.sqlite      # gerado em runtime (NÃO versionar)
```

**.gitignore (recomendado):**

```
data.sqlite
data.backup.sqlite
```

---

## 🧪 Modelos de dados

**Tabela `groups`**

- `id` (INTEGER, PK, 1–850)

**Tabela `entries`**

- `id` (INTEGER, PK autoincrement)
- `group_id` (INTEGER, 1–850)
- `turma` (TEXT, `'001' | '002'`)
- `name` (TEXT, obrigatório, ≤ 80)
- `whatsapp` (TEXT, normalizado para dígitos/`+`)
- `linkedin` (TEXT, domínio `linkedin.com`)
- `created_at` (DATETIME, `CURRENT_TIMESTAMP`)

> Migração automática: recria tabelas com novos _constraints_ e **copia os dados existentes**. Registros antigos recebem `turma = '001'` por padrão.

---

## 🚀 Instalação

### Opção A — cPanel/HostGator (produção)

1. **Enviar arquivos**  
   cPanel → _Gerenciador de Arquivos_ → (ex.: `public_html/projetos/ads/`)  
   Faça upload de `index.php`, `index.html`, `script.js`, `styles.css` (e `.htaccess` se quiser, mas não é necessário).

2. **PHP + SQLite**

   - cPanel → **MultiPHP Manager** → selecione o domínio → PHP **8.x**
   - cPanel → **Select PHP Extensions** → habilite **sqlite3**

3. **Permissões**

   - Pasta do app: **755**
   - Arquivos: **644**
   - O `data.sqlite` é criado automaticamente. Se o host bloquear escrita, crie-o manualmente e use **664** (em último caso, **666** só para validar).

4. **Testes**

   - `https://seu-dominio/caminho/index.php?api=health` → `{"ok":true}`
   - `https://seu-dominio/caminho/index.php?api=groups` → lista `[{ id, count }, ...]`

5. **Abrir a página**  
   `https://seu-dominio/caminho/` → os 850 grupos aparecem; clique num card para abrir o modal, escolha **Turma** e cadastre.

> **Sem .htaccess:** o app funciona com `?api=...`. Você pode usar rewrite se quiser, mas não é obrigatório.

### Opção B — Local (desenvolvimento)

```bash
php -S 127.0.0.1:8080 -t .
```

Abra `http://127.0.0.1:8080/`.

> No primeiro acesso, `data.sqlite` é criado. Garanta permissão de escrita da pasta.

---

## 🔌 API

Todas as rotas usam `index.php?api=...`:

### Health

```
GET ?api=health
→ {"ok": true}
```

### Estatísticas (contador)

```
GET ?api=stats
→ {"total": 42, "by_turma": {"001": 30, "002": 12}}
```

### Grupos (lista + contagem)

```
GET ?api=groups
→ [{ "id": 1, "count": 3 }, ...]
```

### Entradas por grupo

```
GET  ?api=groups/{id}[&turma=001|002]
→ [{ id, group_id, turma, name, whatsapp, linkedin, created_at }, ...]

POST ?api=groups/{id}
Body (JSON):
{
  "name": "Fulana",
  "whatsapp": "+55 11 99999-9999",
  "linkedin": "https://www.linkedin.com/in/fulana",
  "turma": "001"
}
Respostas:
- 200 {"ok": true, "id": 123}
- 400 {"error": "..."}
- 500 {"error": "DB error"}
```

### Busca

```
GET ?api=search&q=texto_ou_numero
→ Se q ∈ [1..850], retorna entradas do grupo.
→ Caso contrário, busca parcial por name/whatsapp/linkedin.
→ [{ id, group_id, turma, name, whatsapp, linkedin, created_at }, ...]
```

---

## 🛠️ Como expandir

- **Mais turmas:**  
  Backend: ampliar `CHECK (turma IN (...))`; Front: adicionar opções no `<select>` e botões no filtro.

- **Moderação:**  
  Coluna `status ('pending'|'approved')` + endpoints de aprovação (apenas admin).

- **Antispam:**  
  reCAPTCHA no formulário; _rate-limit_ simples por IP; usar ModSecurity do cPanel.

- **Exportar CSV (admin):**  
  Endpoint autenticado gerando `entries.csv`.

- **Autenticação/Admin:**  
  Painel `admin.php` protegido via `.htpasswd` do Apache.

- **Campos extras:**  
  Curso/Turno/Cidade — adicionar colunas e exibir no modal.

- **Contagem por turma no card:**  
  Endpoint de stats por grupo+turma e ajuste no grid.

> Siga o padrão de **migração** atual: renomeie a tabela antiga para `_old`, crie a nova com os _constraints_ e **copie** os dados.

---

## 🤔 Por que PHP (e não Node) neste projeto?

**Cenário:** hospedagem compartilhada (cPanel/HostGator).  
Em _shared hosting_, servidores **Node** (processos long-running) costumam não ser suportados ou são finalizados. Há restrições de portas, falta de PM2, necessidade de proxy reverso, etc.

**Vantagens do PHP + SQLite aqui:**

- **Compatibilidade nativa** no cPanel (sem root, sem serviços extras)
- **Zero dependências** de runtime (sem `npm install`, sem `pm2`)
- **Simples/Barato**: apenas arquivos e um banco local (`data.sqlite`)
- **Confiável** para leitura/escrita moderadas do caso de uso
- **Menos pontos de falha** (sem daemons)

> Se migrar para **VPS** ou PaaS (Render/Railway), dá para usar a versão **Node.js** (Express + SQLite/PostgreSQL) com rate-limit, PM2, HTTPS etc.

---

## 🧯 Troubleshooting

- **`?api=health` → 403/404**: use `?api=...` (evite `index.php/...` em hosts que bloqueiam PATH_INFO).
- **500 na API**: ver erros exibidos; confirme `sqlite3` habilitado e permissões (pasta 755, arquivos 644, `data.sqlite` 664).
- **`data.sqlite` não cria**: crie o arquivo vazio e ajuste permissão (664/666).
- **Front não carrega grupos**: force **Ctrl+F5** (cache) e confirme que `script.js` usa `index.php?api=...`.

---

## 🔒 Privacidade & Segurança

- Dados coletados: **nome**, **WhatsApp**, **LinkedIn**, **turma**, **grupo**.
- Recomendações:
  - Ativar **SSL** (Let’s Encrypt) no domínio/subdomínio
  - (Opcional) **reCAPTCHA** no formulário
  - Backups periódicos do `data.sqlite`

---

## 💾 Backup & Restauração

- **Backup:** baixe `data.sqlite` ou duplique para `data.backup.sqlite`.
- **Restauração:** substitua o `data.sqlite` pelo backup.
- Migrações são idempotentes; em falha, mensagens `MIGRATION_*_FAIL` ajudam no diagnóstico.

---

## 📜 Licença

Escolha a licença (ex.: MIT).

```
MIT License
Copyright (c) 2025 ...
Permissão é concedida, gratuitamente, a qualquer pessoa que obtenha uma cópia...
```

---

## 🤝 Contribuindo

Issues e PRs são bem-vindos.  
Antes de abrir PR, descreva a mudança (UI/DB/API), impacto em migração e, se possível, inclua um pequeno plano de rollback.

// API via index.php?api=... + Turma (001/002) com filtro por turma
(function () {
  const here = window.location.pathname;
  const basePath = here.endsWith("/") ? here : here.replace(/[^/]+$/, "");
  const apiBase = basePath + "index.php?api=";

  function apiUrl(path, params) {
    const usp = new URLSearchParams(params || {});
    const qs = usp.toString() ? "&" + usp.toString() : "";
    return apiBase + encodeURIComponent(path) + qs;
  }
  async function apiGet(path, params) {
    const res = await fetch(apiUrl(path, params));
    return res;
  }
  async function apiPost(path, body) {
    const res = await fetch(apiUrl(path), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body || {}),
    });
    return res;
  }

  const groupsGrid = document.getElementById("groupsGrid");
  const searchInput = document.getElementById("searchInput");
  const searchResults = document.getElementById("searchResults");
  const resultsList = document.getElementById("resultsList");

  const modal = document.getElementById("modal");
  const closeModalBtn = document.getElementById("closeModal");
  const modalTitle = document.getElementById("modalTitle");
  const entriesList = document.getElementById("entries");

  const entryForm = document.getElementById("entryForm");
  const nameInput = document.getElementById("name");
  const whatsappInput = document.getElementById("whatsapp");
  const linkedinInput = document.getElementById("linkedin");
  const turmaSelect = document.getElementById("turma");
  const formMsg = document.getElementById("formMsg");

  const fltAll = document.getElementById("flt-all");
  const flt001 = document.getElementById("flt-001");
  const flt002 = document.getElementById("flt-002");

  let currentGroupId = null;
  let currentTurmaFilter = "all"; // 'all' | '001' | '002'

  function setFilterActive(which) {
    [fltAll, flt001, flt002].forEach((b) => b && b.classList.remove("active"));
    if (which === "001") flt001.classList.add("active");
    else if (which === "002") flt002.classList.add("active");
    else fltAll.classList.add("active");
  }

  function createGroupCard(group) {
    const card = document.createElement("button");
    card.className = "group-card";
    card.innerHTML = `<div class="group-id">#${
      group.id
    }</div><div class="count">${group.count || 0}</div>`;
    card.addEventListener("click", () => openGroupModal(group.id));
    return card;
  }

  async function loadGroups() {
    const res = await apiGet("groups");
    const data = await res.json();
    groupsGrid.innerHTML = "";
    data.forEach((g) => groupsGrid.appendChild(createGroupCard(g)));
  }

  function formatWhatsAppLink(val) {
    const digits = (val || "").replace(/[^\d+]/g, "");
    let url = "https://wa.me/";
    let number = digits.startsWith("+") ? digits.slice(1) : digits;
    url += number;
    return `<a href="${url}" target="_blank" rel="noopener">WhatsApp</a>`;
  }

  function entryRow(e) {
    const div = document.createElement("div");
    div.className = "row-item";
    div.innerHTML = `
      <div class="who">
        <strong>${escapeHtml(e.name)}</strong>
        <span class="muted">grupo #${e.group_id} · turma ${
      e.turma || "001"
    }</span>
      </div>
      <div class="links">
        ${formatWhatsAppLink(e.whatsapp)} ·
        <a href="${e.linkedin}" target="_blank" rel="noopener">LinkedIn</a>
      </div>
      <div class="time">${new Date(e.created_at).toLocaleString()}</div>
    `;
    return div;
  }

  function escapeHtml(str) {
    return String(str).replace(
      /[&<>"']/g,
      (s) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        }[s])
    );
  }

  async function loadEntries() {
    if (!currentGroupId) return;
    const params = {};
    if (currentTurmaFilter === "001" || currentTurmaFilter === "002") {
      params.turma = currentTurmaFilter;
    }
    const res = await apiGet(`groups/${currentGroupId}`, params);
    const data = await res.json();
    renderEntries(data);
  }

  async function openGroupModal(id) {
    currentGroupId = id;
    modalTitle.textContent = `Grupo #${id}`;
    entriesList.innerHTML = '<div class="loading">Carregando...</div>';
    modal.classList.remove("hidden");

    nameInput.value = "";
    whatsappInput.value = "";
    linkedinInput.value = "";
    turmaSelect.value = "001";
    currentTurmaFilter = "all";
    setFilterActive("all");

    await loadEntries();
  }

  function renderEntries(items) {
    entriesList.innerHTML = "";
    if (!items.length) {
      entriesList.innerHTML =
        '<div class="empty">Ninguém adicionou contato ainda.</div>';
      return;
    }
    items.forEach((e) => entriesList.appendChild(entryRow(e)));
  }

  // Filtro de turma
  fltAll.addEventListener("click", () => {
    currentTurmaFilter = "all";
    setFilterActive("all");
    loadEntries();
  });
  flt001.addEventListener("click", () => {
    currentTurmaFilter = "001";
    setFilterActive("001");
    loadEntries();
  });
  flt002.addEventListener("click", () => {
    currentTurmaFilter = "002";
    setFilterActive("002");
    loadEntries();
  });

  // Modal
  closeModalBtn.addEventListener("click", () => modal.classList.add("hidden"));
  modal.addEventListener("click", (e) => {
    if (e.target === modal) modal.classList.add("hidden");
  });

  // Submit
  entryForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    formMsg.textContent = "";
    if (!currentGroupId) return;

    const payload = {
      name: nameInput.value.trim(),
      whatsapp: whatsappInput.value.trim(),
      linkedin: linkedinInput.value.trim(),
      turma: turmaSelect.value,
    };

    const btn = entryForm.querySelector("button.primary");
    btn.disabled = true;
    try {
      const res = await apiPost(`groups/${currentGroupId}`, payload);
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Erro ao salvar");
      formMsg.className = "msg success";
      formMsg.textContent = "Contato adicionado!";
      await loadEntries();
      entryForm.reset();
      turmaSelect.value = "001";
    } catch (err) {
      formMsg.className = "msg error";
      formMsg.textContent = err.message;
    } finally {
      btn.disabled = false;
    }
  });

  // Busca
  let searchTimer = null;
  searchInput.addEventListener("input", () => {
    clearTimeout(searchTimer);
    const q = searchInput.value.trim();
    if (!q) {
      searchResults.classList.add("hidden");
      resultsList.innerHTML = "";
      return;
    }
    searchTimer = setTimeout(async () => {
      const res = await apiGet("search", { q });
      const data = await res.json();
      resultsList.innerHTML = "";
      if (data.length) {
        data.forEach((e) => {
          const row = entryRow(e);
          row.querySelector(".who strong").style.cursor = "pointer";
          row
            .querySelector(".who strong")
            .addEventListener("click", () => openGroupModal(e.group_id));
          resultsList.appendChild(row);
        });
      } else {
        resultsList.innerHTML = '<div class="empty">Nada encontrado.</div>';
      }
      searchResults.classList.remove("hidden");
    }, 300);
  });

  // Init
  loadGroups();
})();

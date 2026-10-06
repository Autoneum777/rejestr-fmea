const state = {
  view: "fmea",
  status: "",
  aktywne: "1",
  q: "",
  cpStatus: "",
  cpAktywne: "1",
  cpQ: "",
  currentId: null,
  reviewKind: "fmea",
  editingId: null,
  editingCp: null,
  fmeaItems: [],
  cpItems: [],
  fmeaCols: {},
  cpCols: {},
  settings: {
    color_overdue: "#F8D0CB",
    color_warning: "#FFF2CC",
    warning_days: "30",
  },
};

const $ = (id) => document.getElementById(id);

function applyColors(settings) {
  state.settings = { ...state.settings, ...settings };
  const root = document.documentElement;
  root.style.setProperty("--row-overdue", state.settings.color_overdue);
  root.style.setProperty("--row-warning", state.settings.color_warning);
}

function fmtDate(value) {
  if (!value) return "—";
  const [y, m, d] = String(value).slice(0, 10).split("-");
  if (!d) return value;
  return `${d}.${m}.${y}`;
}

function statusLabel(status) {
  return {
    przeterminowane: "Przeterminowane",
    ostrzezenie: "Miesiąc do końca",
    aktualne: "Aktualne",
    brak_terminu: "Brak terminu",
  }[status] || status;
}

async function api(url, options) {
  const res = await fetch(url, {
    headers: { "Content-Type": "application/json" },
    ...options,
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || "Błąd zapisu");
  return data;
}

function renderStats(summary) {
  $("stats").innerHTML = `
    <div class="stat"><b>${summary.total}</b><span>Pozycji na liście</span></div>
    <div class="stat bad"><b>${summary.przeterminowane}</b><span>Przeterminowane</span></div>
    <div class="stat warn"><b>${summary.ostrzezenie}</b><span>Miesiąc do końca</span></div>
    <div class="stat ok"><b>${summary.aktualne}</b><span>Aktualne</span></div>
    <div class="stat muted-stat"><b>${summary.nieaktywne ?? 0}</b><span>Nieaktywne (poza statystykami)</span></div>
  `;
}

function matchesCols(item, cols) {
  return Object.entries(cols).every(([key, value]) => {
    if (!value) return true;
    const raw = String(item[key] || "").toLowerCase();
    const shown = fmtDate(item[key]).toLowerCase();
    return raw.includes(value) || shown.includes(value);
  });
}

function changesHtml(changes) {
  const rows = (changes || [])
    .map(
      (change) => `
      <tr>
        <td>${fmtDate(change.created_at)}</td>
        <td>${change.field_label}</td>
        <td>${change.old_value || "—"}</td>
        <td>${change.new_value || "—"}</td>
      </tr>`
    )
    .join("");
  return `
    <section class="history">
      <h3>Historia zmian</h3>
      <table>
        <thead><tr><th>Kiedy</th><th>Pole</th><th>Było</th><th>Jest</th></tr></thead>
        <tbody>${rows || `<tr><td colspan="4" class="muted">Brak zmian opisów i statusów.</td></tr>`}</tbody>
      </table>
    </section>`;
}

function renderRows(items) {
  const body = $("rows");
  if (!items.length) {
    body.innerHTML = `<tr><td colspan="8" class="muted">Brak rekordów.</td></tr>`;
    return;
  }
  body.innerHTML = items
    .map(
      (item) => `
      <tr class="row ${item.aktywne ? `row-${item.status}` : "row-inactive"}" data-id="${item.id}">
        <td><strong>${item.nr_fmea}</strong></td>
        <td class="pn">${item.part_numbers || "—"}</td>
        <td>${item.projekt || "—"}</td>
        <td>${item.rodzaj_wyrobu || "—"}</td>
        <td>${item.osoba_wiodaca || "—"}</td>
        <td>${fmtDate(item.current_fmea_date)}</td>
        <td>${item.last_review_reason || "—"}</td>
        <td>${fmtDate(item.next_due_date)}<div class="muted">${item.aktywne ? statusLabel(item.status) : "Nieaktywne"}</div></td>
        <td><button type="button" class="btn small" data-review="${item.id}">Aktualizuj datę</button></td>
      </tr>`
    )
    .join("");
}

async function loadList() {
  const params = new URLSearchParams();
  if (state.q) params.set("q", state.q);
  if (state.status) params.set("status", state.status);
  if (state.aktywne) params.set("aktywne", state.aktywne);
  const data = await api(`/api/fmea?${params.toString()}`);
  state.fmeaItems = data.items;
  renderStats(data.summary);
  renderRows(state.fmeaItems.filter((item) => matchesCols(item, state.fmeaCols)));
  if (data.settings) applyColors(data.settings);
}

function typeLabel(type) {
  return type === "pelny" ? "Pełny" : "Zdarzeniowy";
}

async function openDrawer(id) {
  const data = await api(`/api/fmea/${id}`);
  state.currentId = id;
  const item = data.item;
  $("d-nr").textContent = `FMEA ${item.nr_fmea}`;
  $("d-title").textContent = item.projekt || item.rodzaj_wyrobu || "Szczegóły";
  const history = data.reviews
    .map(
      (r) => `
      <tr>
        <td>${fmtDate(r.review_date)}</td>
        <td class="type-${r.review_type}">${typeLabel(r.review_type)}</td>
        <td>${r.reason || "—"}</td>
        <td>${fmtDate(r.next_due_after)}</td>
      </tr>`
    )
    .join("");
  $("drawer-body").innerHTML = `
    <div class="kv">
      <div class="muted">Wyroby gotowe</div><div>${item.part_numbers || "—"}</div>
      <div class="muted">Projekt</div><div>${item.projekt || "—"}</div>
      <div class="muted">Rodzaj wyrobu</div><div>${item.rodzaj_wyrobu || "—"}</div>
      <div class="muted">Osoba wiodąca</div><div>${item.osoba_wiodaca || "—"}</div>
      <div class="muted">Data obecnego FMEA</div><div>${fmtDate(item.current_fmea_date)}</div>
      <div class="muted">Ostatni pełny FMEA</div><div>${fmtDate(item.last_full_date)}</div>
      <div class="muted">Termin kolejnej rewizji</div><div>${fmtDate(item.next_due_date)}</div>
      <div class="muted">Lata od pełnego FMEA</div><div>${item.years_since_full ?? "—"}</div>
      <div class="muted">Status</div><div><span class="pill ${item.aktywne ? item.status : "brak_terminu"}">${item.aktywne ? statusLabel(item.status) : "Nieaktywne"}</span></div>
      <div class="muted">Uwagi</div><div>${item.uwagi || "—"}</div>
    </div>
    <section class="history">
      <h3>Powiązane plany kontroli (${(data.control_plans || []).length})</h3>
      <table>
        <thead><tr><th>Nr planu</th><th>Symbol</th><th>Termin rewizji</th></tr></thead>
        <tbody>${
          (data.control_plans || []).map((plan) => `
            <tr>
              <td>${plan.nr_planu}</td>
              <td>${plan.symbol_wyrobu || "—"}</td>
              <td>${fmtDate(plan.next_due_date)}</td>
            </tr>`).join("") || `<tr><td colspan="3" class="muted">Brak powiązanych planów.</td></tr>`
        }</tbody>
      </table>
    </section>
    <div class="drawer-actions">
      <button type="button" class="btn" id="btn-add-review">Aktualizuj datę FMEA</button>
      <button type="button" class="btn ghost" id="btn-edit">Edytuj dane</button>
      <button type="button" class="btn ghost" id="btn-toggle-active">${item.aktywne ? "Dezaktywuj projekt" : "Aktywuj projekt"}</button>
      <button type="button" class="btn danger" id="btn-delete">Usuń bezpowrotnie</button>
    </div>
    <section class="history">
      <h3>Historia przeglądów</h3>
      <table>
        <thead>
          <tr>
            <th>Data</th>
            <th>Typ</th>
            <th>Powód</th>
            <th>Termin po zapisie</th>
          </tr>
        </thead>
        <tbody>${history || `<tr><td colspan="4" class="muted">Brak historii.</td></tr>`}</tbody>
      </table>
    </section>
    ${changesHtml(data.changes)}
  `;
  $("drawer").hidden = false;
  $("drawer-backdrop").hidden = false;
  $("btn-add-review").onclick = () => openReviewModal(id, "fmea");
  $("btn-edit").onclick = () => openEditModal(item);
  $("btn-toggle-active").onclick = async () => {
    await api(`/api/fmea/${id}/aktywne`, {
      method: "PUT",
      body: JSON.stringify({ aktywne: item.aktywne ? 0 : 1 }),
    });
    await loadList();
    await openDrawer(id);
  };
  $("btn-delete").onclick = () => openDeleteModal(item);
}

function openDeleteModal(item) {
  const nr = String(item.nr_fmea);
  if (!confirm(`Usunąć FMEA ${nr} wraz z całą historią przeglądów?\n\nTo działanie jest bezpowrotne.`)) {
    return;
  }
  state.currentId = item.id;
  const form = $("delete-form");
  form.reset();
  $("delete-hint").textContent = `Aby potwierdzić, wpisz numer FMEA: ${nr}`;
  form.dataset.nr = nr;
  $("delete-modal").hidden = false;
  form.confirm.focus();
}

function closeDeleteModal() {
  $("delete-modal").hidden = true;
}

function closeDrawer() {
  $("drawer").hidden = true;
  $("drawer-backdrop").hidden = true;
}

function openReviewModal(id, kind) {
  state.currentId = id;
  state.reviewKind = kind || "fmea";
  const form = $("review-form");
  form.querySelector("h3").textContent = state.reviewKind === "cp" ? "Aktualizacja daty planu kontroli" : "Aktualizacja daty FMEA";
  form.reset();
  form.review_date.value = new Date().toISOString().slice(0, 10);
  $("modal").hidden = false;
}

function closeReviewModal() {
  $("modal").hidden = true;
}

function addMonths(isoDate, months) {
  const [y, m, d] = isoDate.split("-").map(Number);
  const date = new Date(y, m - 1 + months, 1);
  const last = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
  date.setDate(Math.min(d, last));
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const dd = String(date.getDate()).padStart(2, "0");
  return `${date.getFullYear()}-${mm}-${dd}`;
}

function updateNextDuePreview() {
  const form = $("edit-form");
  const preview = $("next-due-preview");
  const value = form.fmea_date.value;
  if (!value) {
    preview.textContent = "Termin kolejnego przeglądu: —";
    return;
  }
  preview.textContent = `Termin kolejnego przeglądu: ${fmtDate(addMonths(value, 12))}`;
}

function openEditModal(item) {
  const form = $("edit-form");
  form.reset();
  const dateBlock = $("new-date-block");
  if (item) {
    state.editingId = item.id;
    $("edit-title").textContent = `Edycja FMEA ${item.nr_fmea}`;
    form.nr_fmea.value = item.nr_fmea || "";
    form.part_numbers.value = item.part_numbers || "";
    form.projekt.value = item.projekt || "";
    form.rodzaj_wyrobu.value = item.rodzaj_wyrobu || "";
    form.osoba_wiodaca.value = item.osoba_wiodaca || "";
    form.uwagi.value = item.uwagi || "";
    dateBlock.hidden = true;
    form.fmea_date.required = false;
  } else {
    state.editingId = null;
    $("edit-title").textContent = "Nowe FMEA";
    dateBlock.hidden = false;
    form.fmea_date.required = true;
    form.fmea_date.value = new Date().toISOString().slice(0, 10);
    updateNextDuePreview();
  }
  $("edit-modal").hidden = false;
}

function closeEditModal() {
  $("edit-modal").hidden = true;
}

$("search").addEventListener("input", (e) => {
  state.q = e.target.value.trim();
  loadList();
});

$("filters").addEventListener("click", (e) => {
  const btn = e.target.closest("button[data-aktywne], button[data-status]");
  if (!btn) return;
  state.status = btn.dataset.status || "";
  state.aktywne = btn.dataset.aktywne || "1";
  for (const chip of $("filters").querySelectorAll(".chip")) {
    chip.classList.toggle("active", chip === btn);
  }
  loadList();
});

$("rows").addEventListener("click", (e) => {
  const reviewBtn = e.target.closest("[data-review]");
  if (reviewBtn) {
    e.stopPropagation();
    openReviewModal(Number(reviewBtn.dataset.review), "fmea");
    return;
  }
  const row = e.target.closest("tr[data-id]");
  if (row) openDrawer(Number(row.dataset.id));
});

$("btn-close").onclick = closeDrawer;
$("drawer-backdrop").onclick = closeDrawer;
$("btn-cancel").onclick = closeReviewModal;
$("modal").addEventListener("click", (e) => {
  if (e.target.id === "modal") closeReviewModal();
});
$("btn-new").onclick = () => openEditModal(null);
$("btn-edit-cancel").onclick = closeEditModal;
$("edit-modal").addEventListener("click", (e) => {
  if (e.target.id === "edit-modal") closeEditModal();
});
$("edit-form").fmea_date.addEventListener("input", updateNextDuePreview);

$("review-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  try {
    if (!form.reason.value.trim()) {
      alert("Podaj powód rewizji.");
      return;
    }
    const kind = state.reviewKind;
    await api(`/${kind === "cp" ? "api/cp" : "api/fmea"}/${state.currentId}/reviews`, {
      method: "POST",
      body: JSON.stringify({
        review_date: form.review_date.value,
        review_type: form.review_type.value,
        reason: form.reason.value,
        comment: form.comment.value,
      }),
    });
    closeReviewModal();
    if (kind === "cp") {
      await loadCp();
      if (!$("drawer").hidden) await openCpDrawer(state.currentId);
    } else {
      await loadList();
      if (!$("drawer").hidden) await openDrawer(state.currentId);
    }
  } catch (err) {
    alert(err.message);
  }
});

$("edit-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const payload = {
    nr_fmea: form.nr_fmea.value,
    part_numbers: form.part_numbers.value,
    projekt: form.projekt.value,
    rodzaj_wyrobu: form.rodzaj_wyrobu.value,
    osoba_wiodaca: form.osoba_wiodaca.value,
    uwagi: form.uwagi.value,
  };
  try {
    if (state.editingId) {
      await api(`/api/fmea/${state.editingId}`, {
        method: "PUT",
        body: JSON.stringify(payload),
      });
    } else {
      payload.fmea_date = form.fmea_date.value;
      payload.reason = form.reason.value;
      await api("/api/fmea", { method: "POST", body: JSON.stringify(payload) });
    }
    closeEditModal();
    await loadList();
    if (state.editingId && !$("drawer").hidden) await openDrawer(state.editingId);
  } catch (err) {
    alert(err.message);
  }
});

$("btn-export").onclick = () => {
  const params = new URLSearchParams();
  if (state.q) params.set("q", state.q);
  if (state.status) params.set("status", state.status);
  if (state.aktywne) params.set("aktywne", state.aktywne);
  window.location.href = `/api/export?${params.toString()}`;
};

$("btn-import").onclick = () => $("fmea-file").click();
$("fmea-file").addEventListener("change", async () => {
  const file = $("fmea-file").files[0];
  $("fmea-file").value = "";
  if (!file) return;
  if (!confirm(`Nadpisać rejestr FMEA plikiem „${file.name}”?`)) return;
  const body = new FormData();
  body.append("file", file);
  try {
    const res = await fetch("/api/import", { method: "POST", body });
    const result = await res.json();
    if (!res.ok) throw new Error(result.error || "Błąd importu");
    alert(`Zaimportowano ${result.fmea} FMEA i ${result.reviews} przeglądów.`);
    closeDrawer();
    await loadList();
  } catch (err) {
    alert(err.message);
  }
});

function openSettingsModal() {
  const form = $("settings-form");
  form.color_overdue.value = state.settings.color_overdue.toLowerCase();
  form.color_warning.value = state.settings.color_warning.toLowerCase();
  form.warning_days.value = state.settings.warning_days;
  $("settings-modal").hidden = false;
}

$("btn-settings").onclick = openSettingsModal;
$("btn-settings-cancel").onclick = () => {
  $("settings-modal").hidden = true;
};
$("settings-modal").addEventListener("click", (e) => {
  if (e.target.id === "settings-modal") $("settings-modal").hidden = true;
});
$("settings-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  try {
    const saved = await api("/api/settings", {
      method: "PUT",
      body: JSON.stringify({
        color_overdue: form.color_overdue.value,
        color_warning: form.color_warning.value,
        warning_days: form.warning_days.value,
      }),
    });
    applyColors(saved);
    $("settings-modal").hidden = true;
    await loadList();
  } catch (err) {
    alert(err.message);
  }
});

$("btn-delete-cancel").onclick = closeDeleteModal;
$("delete-modal").addEventListener("click", (e) => {
  if (e.target.id === "delete-modal") closeDeleteModal();
});
$("delete-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const expected = form.dataset.nr || "";
  const typed = form.confirm.value.trim();
  if (typed !== expected) {
    alert(`Wpisz dokładnie numer FMEA: ${expected}`);
    return;
  }
  try {
    await api(`/api/fmea/${state.currentId}`, {
      method: "DELETE",
      body: JSON.stringify({ confirm: typed }),
    });
    closeDeleteModal();
    closeDrawer();
    await loadList();
  } catch (err) {
    alert(err.message);
  }
});

function renderCpStats(summary) {
  $("cp-stats").innerHTML = `
    <div class="stat"><b>${summary.total}</b><span>Aktywnych planów</span></div>
    <div class="stat bad"><b>${summary.przeterminowane}</b><span>Przeterminowane</span></div>
    <div class="stat warn"><b>${summary.ostrzezenie}</b><span>Miesiąc do końca</span></div>
    <div class="stat ok"><b>${summary.aktualne}</b><span>Aktualne</span></div>
    <div class="stat muted-stat"><b>${summary.nieaktywne ?? 0}</b><span>Serwis, poza statystykami</span></div>
  `;
}

function renderCpRows(items) {
  const body = $("cp-rows");
  if (!items.length) {
    body.innerHTML = `<tr><td colspan="10" class="muted">Brak planów kontroli.</td></tr>`;
    return;
  }
  body.innerHTML = items
    .map(
      (item) => `
      <tr class="row ${item.aktywne ? `row-${item.status}` : "row-inactive"}" data-cp="${item.id}">
        <td><strong>${item.nr_planu}</strong></td>
        <td>${item.nr_fmea ? `${item.nr_fmea}${item.fmea_linked ? "" : " <span class='muted'>(brak w rejestrze)</span>"}` : "—"}</td>
        <td>${item.osoba_wiodaca || "—"}</td>
        <td class="pn">${item.symbol_wyrobu || "—"}</td>
        <td>${item.model || "—"}</td>
        <td>${item.nr_rysunku || "—"}</td>
        <td>${item.linia_mes || "—"}</td>
        <td>${fmtDate(item.last_review_date)}</td>
        <td>${fmtDate(item.next_due_date)}<div class="muted">${item.aktywne ? statusLabel(item.status) : "Nieaktywny"}</div></td>
        <td><button type="button" class="btn small" data-cp-review="${item.id}">Aktualizuj datę</button></td>
      </tr>`
    )
    .join("");
}

async function loadCp() {
  const params = new URLSearchParams();
  if (state.cpQ) params.set("q", state.cpQ);
  if (state.cpStatus) params.set("status", state.cpStatus);
  if (state.cpAktywne) params.set("aktywne", state.cpAktywne);
  const data = await api(`/api/cp?${params.toString()}`);
  state.cpItems = data.items;
  renderCpStats(data.summary);
  renderCpRows(state.cpItems.filter((item) => matchesCols(item, state.cpCols)));
  if (data.settings) applyColors(data.settings);
}

async function openCpDrawer(id) {
  const data = await api(`/api/cp/${id}`);
  state.currentId = id;
  state.reviewKind = "cp";
  const item = data.item;
  $("d-nr").textContent = item.nr_planu;
  $("d-title").textContent = item.nazwa_wyrobu || item.symbol_wyrobu || "Plan kontroli";
  const history = data.reviews
    .map(
      (review) => `
      <tr>
        <td>${fmtDate(review.review_date)}</td>
        <td class="type-${review.review_type}">${typeLabel(review.review_type)}</td>
        <td>${review.reason || "—"}</td>
        <td>${fmtDate(review.next_due_after)}</td>
      </tr>`
    )
    .join("");
  $("drawer-body").innerHTML = `
    <div class="kv">
      <div class="muted">Plan kontroli</div><div>${item.nr_planu}</div>
      <div class="muted">Numer FMEA</div><div>${item.nr_fmea || "—"}${item.fmea_linked ? ` · ${item.fmea_projekt || ""}` : ""}</div>
      <div class="muted">Osoba wiodąca</div><div>${item.osoba_wiodaca || "—"}</div>
      <div class="muted">Symbol wyrobu</div><div>${item.symbol_wyrobu || "—"}</div>
      <div class="muted">Model samochodu</div><div>${item.model || "—"}</div>
      <div class="muted">Nazwa wyrobu</div><div>${item.nazwa_wyrobu || "—"}</div>
      <div class="muted">Data rewizji</div><div>${fmtDate(item.last_review_date)}</div>
      <div class="muted">Termin kolejnej rewizji</div><div>${fmtDate(item.next_due_date)}</div>
      <div class="muted">Status</div><div><span class="pill ${item.aktywne ? item.status : "brak_terminu"}">${item.aktywne ? statusLabel(item.status) : "Nieaktywny"}</span></div>
      <div class="muted">Status z pliku</div><div>${item.status_przegladu || "—"}</div>
      <div class="muted">Nazwa wyrobu</div><div>${item.nazwa_wyrobu || "—"}</div>
      <div class="muted">Nr zmiany</div><div>${item.nr_zmiany || "—"}</div>
      <div class="muted">Data zatwierdzenia</div><div>${fmtDate(item.data_zatwierdzenia)}</div>
      <div class="muted">Nr rewizji</div><div>${item.nr_rewizji || "—"}</div>
      <div class="muted">Uwagi</div><div>${item.uwagi || "—"}</div>
    </div>
    <div class="drawer-actions">
      <button type="button" class="btn" id="btn-cp-review">Aktualizuj datę</button>
      <button type="button" class="btn ghost" id="btn-cp-edit">Edytuj dane</button>
    </div>
    <section class="history">
      <h3>Historia rewizji</h3>
      <table>
        <thead><tr><th>Data</th><th>Typ</th><th>Powód</th><th>Termin po zapisie</th></tr></thead>
        <tbody>${history || `<tr><td colspan="4" class="muted">Brak historii.</td></tr>`}</tbody>
      </table>
    </section>
    <section class="history">
      <h3>Data anulowania</h3>
      <p>${fmtDate(item.data_anulowania)}</p>
    </section>
    ${changesHtml(data.changes)}
  `;
  $("drawer").hidden = false;
  $("drawer-backdrop").hidden = false;
  $("btn-cp-review").onclick = () => openReviewModal(id, "cp");
  $("btn-cp-edit").onclick = () => openCpForm(item);
}

function syncStickyHeaders() {
  for (const table of document.querySelectorAll(".table-wrap table")) {
    const head = table.querySelector("thead tr:first-child");
    if (head && head.offsetHeight) table.style.setProperty("--head-h", `${head.offsetHeight}px`);
  }
}
window.addEventListener("resize", syncStickyHeaders);

function showView(name) {
  state.view = name;
  $("view-fmea").hidden = name !== "fmea";
  $("view-cp").hidden = name !== "cp";
  for (const tab of document.querySelectorAll(".brandbar-tab")) {
    tab.classList.toggle("active", tab.dataset.view === name);
  }
  closeDrawer();
  syncStickyHeaders();
  if (name === "cp") loadCp().catch((err) => alert(err.message));
}

document.querySelector(".brandbar").addEventListener("click", (e) => {
  const tab = e.target.closest(".brandbar-tab");
  if (tab) showView(tab.dataset.view);
});

$("cp-search").addEventListener("input", (e) => {
  state.cpQ = e.target.value.trim();
  loadCp();
});

$("cp-filters").addEventListener("click", (e) => {
  const btn = e.target.closest("button[data-aktywne], button[data-status]");
  if (!btn) return;
  state.cpStatus = btn.dataset.status || "";
  state.cpAktywne = btn.dataset.aktywne || "1";
  for (const chip of $("cp-filters").querySelectorAll(".chip")) {
    chip.classList.toggle("active", chip === btn);
  }
  loadCp();
});

$("cp-rows").addEventListener("click", (e) => {
  const reviewBtn = e.target.closest("[data-cp-review]");
  if (reviewBtn) {
    e.stopPropagation();
    openReviewModal(Number(reviewBtn.dataset.cpReview), "cp");
    return;
  }
  const row = e.target.closest("tr[data-cp]");
  if (row) openCpDrawer(Number(row.dataset.cp));
});

function bindColumnFilters(selector, attr, target) {
  for (const input of document.querySelectorAll(selector)) {
    input.addEventListener("input", () => {
      target[input.dataset[attr]] = input.value.trim().toLowerCase();
      if (attr === "fmeaCol") {
        renderRows(state.fmeaItems.filter((item) => matchesCols(item, state.fmeaCols)));
      } else {
        renderCpRows(state.cpItems.filter((item) => matchesCols(item, state.cpCols)));
      }
    });
  }
}
bindColumnFilters("[data-fmea-col]", "fmeaCol", state.fmeaCols);
bindColumnFilters("[data-cp-col]", "cpCol", state.cpCols);

function updateCpDuePreview() {
  const value = $("cp-form").data_rewizji.value;
  $("cp-due-preview").textContent = value
    ? `Termin kolejnej rewizji: ${fmtDate(addMonths(value, 12))}`
    : "Termin kolejnej rewizji: —";
}

function openCpForm(item) {
  const form = $("cp-form");
  form.reset();
  const creating = !item;
  state.editingCp = item ? item.id : null;
  $("cp-form-title").textContent = creating ? "Nowy plan kontroli" : `Edycja ${item.nr_planu}`;
  form.reason.required = creating;
  form.data_rewizji.required = creating;
  form.reason.closest("label").hidden = !creating;
  form.data_rewizji.closest("label").hidden = !creating;
  $("cp-due-preview").hidden = !creating;
  if (item) {
    form.nr_planu.value = item.nr_planu || "";
    form.nr_fmea.value = item.nr_fmea || "";
    form.qe.value = item.qe || item.osoba_wiodaca || "";
    form.symbol_wyrobu.value = item.symbol_wyrobu || "";
    form.model.value = item.model || "";
    form.nr_rysunku.value = item.nr_rysunku || "";
    form.linia_mes.value = item.linia_mes || "";
    form.status_przegladu.value = item.status_przegladu || "OK";
    form.nazwa_wyrobu.value = item.nazwa_wyrobu || "";
    form.nr_zmiany.value = item.nr_zmiany || "";
    form.data_zatwierdzenia.value = (item.data_zatwierdzenia || "").slice(0, 10);
    form.nr_rewizji.value = item.nr_rewizji || "";
    form.data_anulowania.value = (item.data_anulowania || "").slice(0, 10);
    form.uwagi.value = item.uwagi || "";
  } else {
    form.data_rewizji.value = new Date().toISOString().slice(0, 10);
    form.status_przegladu.value = "OK";
    updateCpDuePreview();
  }
  $("cp-modal").hidden = false;
}

$("btn-cp-new").onclick = () => openCpForm(null);
$("btn-cp-cancel").onclick = () => {
  $("cp-modal").hidden = true;
};
$("cp-modal").addEventListener("click", (e) => {
  if (e.target.id === "cp-modal") $("cp-modal").hidden = true;
});
$("cp-form").data_rewizji.addEventListener("input", updateCpDuePreview);
$("cp-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.target;
  const payload = {
    nr_planu: form.nr_planu.value,
    nr_fmea: form.nr_fmea.value,
    qe: form.qe.value,
    symbol_wyrobu: form.symbol_wyrobu.value,
    model: form.model.value,
    nr_rysunku: form.nr_rysunku.value,
    linia_mes: form.linia_mes.value,
    status_przegladu: form.status_przegladu.value,
    nazwa_wyrobu: form.nazwa_wyrobu.value,
    nr_zmiany: form.nr_zmiany.value,
    data_zatwierdzenia: form.data_zatwierdzenia.value,
    nr_rewizji: form.nr_rewizji.value,
    data_anulowania: form.data_anulowania.value,
    uwagi: form.uwagi.value,
  };
  try {
    if (state.editingCp) {
      await api(`/api/cp/${state.editingCp}`, { method: "PUT", body: JSON.stringify(payload) });
    } else {
      if (!form.reason.value.trim()) {
        alert("Podaj powód rewizji.");
        return;
      }
      payload.data_rewizji = form.data_rewizji.value;
      payload.reason = form.reason.value;
      await api("/api/cp", { method: "POST", body: JSON.stringify(payload) });
    }
    $("cp-modal").hidden = true;
    await loadCp();
    if (state.editingCp && !$("drawer").hidden) await openCpDrawer(state.editingCp);
  } catch (err) {
    alert(err.message);
  }
});

$("btn-cp-settings").onclick = openSettingsModal;
$("btn-cp-export").onclick = () => {
  const params = new URLSearchParams();
  if (state.cpQ) params.set("q", state.cpQ);
  if (state.cpStatus) params.set("status", state.cpStatus);
  if (state.cpAktywne) params.set("aktywne", state.cpAktywne);
  window.location.href = `/api/cp/export?${params.toString()}`;
};

$("btn-cp-import").onclick = () => $("cp-file").click();
$("cp-file").addEventListener("change", async () => {
  const file = $("cp-file").files[0];
  $("cp-file").value = "";
  if (!file) return;
  if (!confirm(`Nadpisać rejestr planów kontroli plikiem „${file.name}”?`)) return;
  const body = new FormData();
  body.append("file", file);
  try {
    const res = await fetch("/api/cp/import", { method: "POST", body });
    const result = await res.json();
    if (!res.ok) throw new Error(result.error || "Błąd importu");
    alert(`Zaimportowano ${result.plans} planów, powiązanych z FMEA: ${result.linked}, nieaktywnych: ${result.inactive}.`);
    closeDrawer();
    await loadCp();
  } catch (err) {
    alert(err.message);
  }
});

loadList()
  .then(syncStickyHeaders)
  .catch((err) => {
    $("rows").innerHTML = `<tr><td colspan="9">${err.message}</td></tr>`;
  });

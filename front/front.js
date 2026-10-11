// Site public : carte des points publiés, fiche d'un point, ajout et proposition de modification.
// Tout le contenu venant de l'API est inséré comme texte, jamais comme HTML.

const panel = document.getElementById('panel');
const picker = document.getElementById('picker');
const select = document.getElementById('collection');

const SUMMARY_TYPES = ['select', 'multiselect', 'text', 'number'];
const DEFAULT_IMAGES = 1;
const DEFAULT_IMAGE_MB = 5;

let config;
let collection;
let map;
let layer;
let draft = null; // repère du point en cours d'ajout ou de déplacement
let loading = null;
const markers = new Map();

/** Crée un élément ; les enfants texte sont insérés comme texte. */
function h(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value === undefined || value === null || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else if (key in node && typeof value !== 'string') node[key] = value;
    else node.setAttribute(key, value === true ? '' : value);
  }
  node.append(...children.flat(Infinity).filter((child) => child !== null && child !== undefined && child !== false));
  return node;
}

async function api(path, options = {}) {
  const response = await fetch(`api/${path}`, options);
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(data.error || 'Erreur de communication avec le serveur.');
    error.details = data.details ?? {};
    throw error;
  }
  return data;
}

const byId = (id) => config.collections.find((c) => c.id === id);

function show(...children) {
  // Les éléments absents (null) sont ignorés, sinon le navigateur les affiche en texte.
  panel.replaceChildren(...children.filter((child) => child !== null && child !== undefined), h('p', { class: 'mt-auto border-t border-line pt-3 text-meta text-muted' }, 'Données publiées avec ', h('a', { href: 'https://geocrowd-api.org' }, 'geocrowd'), '.'));
  panel.scrollTop = 0;
}

/** Titre lisible d'un point : ses deux premières valeurs courtes. */
function summary(feature, c) {
  const values = c.fields
    .filter((field) => SUMMARY_TYPES.includes(field.type))
    .map((field) => feature.properties[field.name])
    .filter((value) => value !== undefined && value !== '' && !(Array.isArray(value) && !value.length))
    .map((value) => (Array.isArray(value) ? value.join(', ') : value))
    .slice(0, 2);
  return values.length ? values.join(' · ') : `Point n° ${feature.id}`;
}

function formatDate(value) {
  const [y, m, d] = value.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('fr-FR', { dateStyle: 'long' });
}

/** Valeur d'un champ, mise en forme selon son type. */
function display(field, value) {
  switch (field.type) {
    case 'image':
      return h('ul', { class: 'm-0 flex list-none flex-wrap gap-2 p-0 [&_img]:block [&_img]:size-24 [&_img]:rounded [&_img]:bg-soft [&_img]:object-cover' }, value.map((url, i) => h('li', {},
        h('a', { href: url, target: '_blank', rel: 'noopener' }, h('img', { src: url, alt: `${field.label} ${i + 1}`, loading: 'lazy' })))));
    case 'boolean':
      return value ? 'Oui' : 'Non';
    case 'multiselect':
      return value.join(', ');
    case 'number':
      return value.toLocaleString('fr-FR');
    case 'date':
      return formatDate(value);
    case 'url':
      // Seules les adresses web deviennent des liens (pas de javascript: ni data:).
      return /^https?:\/\//i.test(value) ? h('a', { href: value, target: '_blank', rel: 'nofollow ugc noopener' }, value) : value;
    case 'ref':
      return byId(field.collection)
        ? h('button', { type: 'button', class: 'link', onclick: () => openPoint(field.collection, value) }, `Point n° ${value}`)
        : `Point n° ${value}`;
    default:
      return String(value);
  }
}

// Carte

function pointStyle(selected) {
  return { radius: selected ? 9 : 6, weight: 2, color: '#ffffff', fillColor: selected ? '#1b1b1b' : getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(), fillOpacity: 1 };
}

function highlight(id) {
  for (const [markerId, marker] of markers) {
    marker.setStyle(pointStyle(markerId === id));
    if (markerId === id) marker.bringToFront();
  }
}

async function loadPoints() {
  loading?.abort();
  loading = new AbortController();
  const b = map.getBounds().pad(0.2);
  const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].map((n) => n.toFixed(5)).join(',');
  try {
    const data = await api(`collections/${collection.id}/points?bbox=${bbox}`, { signal: loading.signal });
    layer.clearLayers();
    markers.clear();
    for (const feature of data.features) {
      const [lng, lat] = feature.geometry.coordinates;
      const marker = L.circleMarker([lat, lng], pointStyle(feature.id === currentId()))
        .on('click', () => showPoint(feature))
        .addTo(layer);
      // Nœud texte : une chaîne serait insérée par Leaflet comme du HTML.
      marker.bindTooltip(document.createTextNode(summary(feature, collection)));
      markers.set(feature.id, marker);
    }
  } catch (e) {
    if (e.name !== 'AbortError') console.error(e);
  }
}

function currentId() {
  const [, id] = location.hash.slice(1).split('/');
  return id ? Number(id) : null;
}

/** Repère déplaçable du point en cours d'ajout ou de modification ; null pour le retirer. */
function placeDraft(latlng, onMove) {
  draft?.remove();
  draft = null;
  map.off('click');
  if (!onMove) return;
  const place = (position) => {
    if (!draft) {
      draft = L.marker(position, { draggable: true, icon: L.divIcon({ className: 'marker-new', iconSize: [18, 18] }) }).addTo(map);
      draft.on('dragend', () => onMove(draft.getLatLng()));
    } else {
      draft.setLatLng(position);
    }
    onMove(L.latLng(position));
  };
  if (latlng) place(latlng);
  map.on('click', (e) => place(e.latlng));
}

// Vues du panneau

function home() {
  placeDraft(null);
  history.replaceState(null, '', `#${collection.id}`);
  highlight(null);
  show(
    config.intro ? h('p', { class: 'whitespace-pre-line' }, config.intro) : null,
    h('h2', {}, collection.name),
    collection.description ? h('p', { class: 'whitespace-pre-line' }, collection.description) : null,
    h('p', { class: 'text-sm text-muted' }, collection.count > 1 ? `${collection.count} points publiés` : `${collection.count} point publié`),
    collection.can_submit ? h('div', { class: 'flex flex-wrap gap-2' }, h('button', { type: 'button', class: 'btn btn-primary', onclick: () => pointForm(collection) }, 'Ajouter un point')) : null,
    h('p', { class: 'text-sm text-muted' }, 'Sélectionner un point sur la carte affiche sa fiche.'),
  );
}

async function openPoint(collectionId, id) {
  try {
    if (collectionId !== collection.id) {
      await switchCollection(collectionId, false);
    }
    const feature = await api(`collections/${collectionId}/points/${id}`);
    const [lng, lat] = feature.geometry.coordinates;
    if (!map.getBounds().contains([lat, lng])) {
      map.setView([lat, lng], Math.max(map.getZoom(), 15));
    }
    showPoint(feature);
  } catch (e) {
    show(h('button', { type: 'button', class: 'back', onclick: home }, '← Retour'), h('p', { class: 'error' }, e.message));
  }
}

function showPoint(feature) {
  placeDraft(null);
  history.replaceState(null, '', `#${collection.id}/${feature.id}`);
  highlight(feature.id);
  const c = collection;
  const rows = c.fields
    .filter((field) => {
      const value = feature.properties[field.name];
      return value !== undefined && value !== null && value !== '' && !(Array.isArray(value) && !value.length);
    })
    .map((field) => [h('dt', {}, field.label), h('dd', {}, display(field, feature.properties[field.name]))]);

  const related = h('div', { class: 'flex flex-col gap-2 border-t border-line pt-3 [&_ul]:list-disc [&_ul]:pl-[18px]' });
  related.hidden = true;

  show(
    h('button', { type: 'button', class: 'back', onclick: home }, '← Retour'),
    h('h2', {}, summary(feature, c)),
    h('p', { class: 'text-sm text-muted' }, `${c.name} · n° ${feature.id} · ajouté le ${new Date(feature.properties.created_at).toLocaleDateString('fr-FR', { dateStyle: 'long' })}`),
    rows.length ? h('dl', { class: 'm-0 [&_dd]:mt-0.5 [&_dd]:wrap-anywhere [&_dt]:mt-3.5 [&_dt]:text-meta [&_dt]:text-muted [&_dt:first-child]:mt-0' }, rows) : null,
    c.can_edit ? h('div', { class: 'flex flex-wrap gap-2' }, h('button', { type: 'button', class: 'btn', onclick: () => pointForm(c, feature) }, 'Proposer une modification')) : null,
    related,
  );
  showRelated(feature, related);
}

/** Points d'autres collections qui désignent celui-ci, et ajout d'un point lié. */
async function showRelated(feature, box) {
  const links = config.collections.flatMap((c) => c.fields
    .filter((field) => field.type === 'ref' && field.collection === collection.id)
    .map((field) => ({ c, field })));
  for (const { c, field } of links) {
    const { features } = await api(`collections/${c.id}/points?${encodeURIComponent(field.name)}=${feature.id}`).catch(() => ({ features: [] }));
    const section = h('div', {},
      h('h3', {}, `${c.name} (${features.length})`),
      features.length ? h('ul', {}, features.map((f) => h('li', {}, h('button', { type: 'button', class: 'link', onclick: () => openPoint(c.id, f.id) }, summary(f, c))))) : null,
      c.can_submit ? h('div', { class: 'flex flex-wrap gap-2' }, h('button', { type: 'button', class: 'btn', onclick: () => pointForm(c, null, { [field.name]: feature.id }, feature) }, `Ajouter : ${c.name}`)) : null,
    );
    if (features.length || c.can_submit) {
      box.append(section);
      box.hidden = false;
    }
  }
}

// Formulaires

/** Champ de formulaire d'un type donné ; `read()` renvoie sa valeur, `files()` les images ajoutées. */
function input(field, value) {
  const id = `f-${field.name}`;
  const label = `${field.label}${field.required ? ' (obligatoire)' : ''}`;
  const error = h('span', { class: 'error', 'data-error': field.name });
  const wrap = (control, hint) => h('label', { class: 'field', for: id }, h('span', {}, label), control, hint ? h('small', {}, hint) : null, error);

  switch (field.type) {
    case 'textarea': {
      const control = h('textarea', { id, class: 'input', rows: 4, maxLength: field.maxLength ?? 2000 });
      control.value = value ?? '';
      return { node: wrap(control), read: () => control.value.trim() };
    }
    case 'number': {
      const control = h('input', { id, class: 'input', type: 'number', step: 'any', min: field.min, max: field.max });
      control.value = value ?? '';
      return { node: wrap(control), read: () => (control.value === '' ? null : control.valueAsNumber) };
    }
    case 'select': {
      const control = h('select', { id, class: 'input' }, h('option', { value: '' }, ''), field.options.map((o) => h('option', { value: o }, o)));
      control.value = value ?? '';
      return { node: wrap(control), read: () => control.value };
    }
    case 'multiselect': {
      const boxes = field.options.map((o) => h('input', { type: 'checkbox', value: o, checked: (value ?? []).includes(o) }));
      const node = h('fieldset', { class: 'field' }, h('legend', {}, label), boxes.map((box) => h('label', { class: 'check' }, box, box.value)), error);
      return { node, read: () => boxes.filter((box) => box.checked).map((box) => box.value) };
    }
    case 'boolean': {
      const control = h('input', { id, type: 'checkbox', checked: Boolean(value) });
      return { node: h('div', { class: 'field' }, h('label', { class: 'check', for: id }, control, field.label), error), read: () => control.checked };
    }
    case 'date': {
      const control = h('input', { id, class: 'input', type: 'date' });
      control.value = value ?? '';
      return { node: wrap(control), read: () => control.value };
    }
    case 'ref': {
      const target = byId(field.collection);
      const control = h('input', { id, class: 'input', type: 'number', min: 1, step: 1 });
      control.value = value ?? '';
      return { node: wrap(control, `Numéro d'un point publié${target ? ` de « ${target.name} »` : ''}.`), read: () => (control.value === '' ? null : control.valueAsNumber) };
    }
    case 'image': {
      const kept = [...(value ?? [])];
      const list = h('ul', { class: 'm-0 flex list-none flex-wrap gap-2 p-0 [&_img]:size-[72px] [&_img]:rounded [&_img]:object-cover [&_li]:flex [&_li]:flex-col [&_li]:items-start [&_li]:gap-1 [&_li]:text-meta' }, kept.map((url) => {
        const item = h('li', {}, h('img', { src: url, alt: '' }), h('button', {
          type: 'button', class: 'link', onclick: () => { kept.splice(kept.indexOf(url), 1); item.remove(); },
        }, 'Retirer'));
        return item;
      }));
      const control = h('input', { id, type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: (field.max ?? DEFAULT_IMAGES) > 1 });
      const hint = `${field.max ?? DEFAULT_IMAGES} image(s) au maximum, ${field.maxSize ?? DEFAULT_IMAGE_MB} Mo chacune (JPEG, PNG ou WebP).`;
      return { node: wrap(h('div', { class: 'field' }, list, control), hint), read: () => kept, files: () => [...control.files] };
    }
    default: {
      const control = h('input', { id, class: 'input', type: field.type === 'url' ? 'url' : 'text', maxLength: field.maxLength ?? (field.type === 'url' ? 500 : 255) });
      control.value = value ?? '';
      return { node: wrap(control), read: () => control.value.trim() };
    }
  }
}

const empty = (value) => value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length);

/**
 * Formulaire d'ajout (feature null) ou de proposition de modification.
 * `preset` : valeurs initiales d'un ajout ; `near` : point dont l'ajout reprend la position.
 */
function pointForm(c, feature = null, preset = {}, near = null) {
  const editing = Boolean(feature);
  const start = feature ?? near;
  const origin = start ? L.latLng(start.geometry.coordinates[1], start.geometry.coordinates[0]) : null;
  let position = origin;

  if (c.id !== collection.id) {
    switchCollection(c.id, false);
  }

  const status = h('p', { class: 'position' });
  const showPosition = () => {
    status.textContent = position
      ? `Position : ${position.lat.toFixed(6)}, ${position.lng.toFixed(6)}. Cliquer sur la carte ou faire glisser le repère pour la modifier.`
      : 'Cliquer sur la carte pour placer le point.';
  };
  showPosition();

  const fields = c.fields.map((field) => ({ field, ...input(field, editing ? feature.properties[field.name] : preset[field.name]) }));
  const comment = editing ? h('textarea', { id: 'f-comment', class: 'input', rows: 3, maxLength: 1000 }) : null;
  const trap = h('input', { name: 'website', tabIndex: -1, autocomplete: 'off' });
  const error = h('p', { class: 'error' });
  const submit = h('button', { type: 'submit', class: 'btn btn-primary' }, editing ? 'Envoyer la proposition' : 'Envoyer');

  const form = h('form', { class: 'flex flex-col gap-3.5', novalidate: true },
    status,
    fields.map((f) => f.node),
    editing ? h('label', { class: 'field', for: 'f-comment' }, h('span', {}, 'Commentaire pour l\'équipe de modération'), comment) : null,
    h('div', { class: 'trap', 'aria-hidden': 'true' }, h('label', {}, 'Ne pas remplir', trap)),
    error,
    h('div', { class: 'flex flex-wrap gap-2' }, submit, h('button', { type: 'button', class: 'btn', onclick: () => (editing ? showPoint(feature) : home()) }, 'Annuler')),
  );

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    error.textContent = '';
    for (const slot of form.querySelectorAll('[data-error]')) slot.textContent = '';
    if (!position) {
      error.textContent = 'Placez d\'abord le point sur la carte.';
      return;
    }

    const body = new FormData();
    const properties = {};
    for (const { field, read, files } of fields) {
      const value = read();
      const added = files?.() ?? [];
      for (const file of added) body.append(`${field.name}[]`, file);
      if (editing) {
        // Proposition : seuls les champs modifiés sont envoyés (null pour vider).
        const before = feature.properties[field.name];
        // Une case non cochée équivaut à un champ oui/non jamais renseigné.
        const same = field.type === 'boolean'
          ? value === Boolean(before)
          : JSON.stringify(empty(value) ? null : value) === JSON.stringify(empty(before) ? null : before);
        if (!same || added.length) properties[field.name] = empty(value) ? null : value;
      } else if (!empty(value)) {
        properties[field.name] = value;
      }
    }

    const data = { properties, website: trap.value };
    if (!editing || !position.equals(origin, 1e-7)) {
      data.lat = Number(position.lat.toFixed(7));
      data.lng = Number(position.lng.toFixed(7));
    }
    if (editing && comment.value.trim()) data.comment = comment.value.trim();
    body.append('data', JSON.stringify(data));

    submit.disabled = true;
    try {
      const result = await api(`collections/${c.id}/points${editing ? `/${feature.id}` : ''}`, { method: 'POST', body });
      sent(result.status, editing);
    } catch (e) {
      error.textContent = e.message;
      for (const [name, message] of Object.entries(e.details ?? {})) {
        const slot = form.querySelector(`[data-error="${CSS.escape(name)}"]`);
        if (slot) slot.textContent = message;
      }
      submit.disabled = false;
    }
  });

  show(
    h('button', { type: 'button', class: 'back', onclick: () => (editing ? showPoint(feature) : home()) }, '← Retour'),
    h('h2', {}, editing ? `Modifier : ${summary(feature, c)}` : `Ajouter : ${c.name}`),
    editing ? h('p', { class: 'text-sm text-muted' }, 'Indiquer les informations à corriger. La proposition est examinée avant d\'être appliquée.') : null,
    form,
  );
  placeDraft(origin, (latlng) => {
    position = latlng;
    showPosition();
  });
}

function sent(status, editing) {
  placeDraft(null);
  const message = status === 'published'
    ? (editing ? 'La modification est enregistrée et publiée.' : 'Le point est enregistré et publié.')
    : (editing ? 'La proposition est enregistrée. Elle sera appliquée après validation par l\'équipe de modération.' : 'Le point est enregistré. Il sera publié après validation par l\'équipe de modération.');
  show(h('p', { class: 'notice' }, message), h('div', { class: 'flex flex-wrap gap-2' }, h('button', { type: 'button', class: 'btn', onclick: home }, 'Retour à la carte')));
  if (status === 'published') loadPoints();
}

// Démarrage

async function switchCollection(id, goHome = true) {
  collection = byId(id) ?? config.collections[0];
  select.value = collection.id;
  await loadPoints();
  if (goHome) home();
}

async function start() {
  try {
    config = await api('front');
  } catch (e) {
    show(h('p', { class: 'error' }, e.message));
    return;
  }
  document.title = config.title;
  document.getElementById('title').textContent = config.title;

  map = L.map('map', { preferCanvas: true }).setView(config.center, config.zoom);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map);
  layer = L.layerGroup().addTo(map);
  let timer;
  map.on('moveend', () => {
    clearTimeout(timer);
    timer = setTimeout(loadPoints, 250);
  });

  if (!config.collections.length) {
    show(config.intro ? h('p', { class: 'whitespace-pre-line' }, config.intro) : null, h('p', { class: 'text-sm text-muted' }, 'Aucune collection n\'est publiée pour le moment.'));
    return;
  }

  select.replaceChildren(...config.collections.map((c) => h('option', { value: c.id }, c.name)));
  picker.hidden = config.collections.length < 2;
  select.addEventListener('change', () => switchCollection(select.value));

  // Lien direct : #collection ou #collection/numéro
  const [id, pointId] = decodeURIComponent(location.hash.slice(1)).split('/');
  await switchCollection(byId(id) ? id : config.collections[0].id, !pointId);
  if (pointId) openPoint(collection.id, Number(pointId));
}

start();

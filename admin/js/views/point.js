// Création et édition d'un point : position sur la carte et champs définis par sa collection.

import { api, fileUrl } from '../api.js';
import { busy, clone, fill, formatDate, showError, statusBadge } from '../dom.js';
import * as map from '../map.js';

const DEFAULT_IMAGES = 1;
const DEFAULT_IMAGE_MB = 5;

/** Construit le champ d'un type donné ; `read()` renvoie sa valeur. */
function buildField(field, value) {
  const el = clone(`tpl-field-${field.type}`);
  const input = el.querySelector('[data-input]');
  el.dataset.name = field.name;
  el.querySelector('[data-label]').textContent = (field.label ?? field.name) + (field.required ? ' *' : '');

  switch (field.type) {
    case 'boolean':
      input.checked = Boolean(value);
      return { el, read: () => input.checked };

    case 'select':
      for (const option of field.options ?? []) {
        input.add(new Option(option, option));
      }
      input.value = value ?? '';
      return { el, read: () => input.value };

    case 'multiselect':
      for (const option of field.options ?? []) {
        const choice = clone('tpl-choice');
        const box = choice.querySelector('input');
        box.value = option;
        box.checked = (value ?? []).includes(option);
        fill(choice, { option });
        input.append(choice);
      }
      return { el, read: () => [...input.querySelectorAll(':checked')].map((box) => box.value) };

    case 'ref':
      el.querySelector('[data-hint]').textContent = `Numéro d'un point publié de la collection « ${field.collection} ».`;
      input.value = value ?? '';
      return { el, read: () => (input.value === '' ? null : input.valueAsNumber) };

    case 'number':
      if ('min' in field) input.min = field.min;
      if ('max' in field) input.max = field.max;
      input.value = value ?? '';
      return { el, read: () => (input.value === '' ? null : input.valueAsNumber) };

    case 'image':
      return buildImageField(el, input, field, value ?? []);

    default:
      if (field.maxLength) input.maxLength = field.maxLength;
      input.value = value ?? '';
      return { el, read: () => input.value };
  }
}

function buildImageField(el, input, field, names) {
  const kept = [...names];
  const thumbs = el.querySelector('[data-thumbs]');
  el.querySelector('[data-hint]').textContent =
    `${field.max ?? DEFAULT_IMAGES} image(s) maximum, ${field.maxSize ?? DEFAULT_IMAGE_MB} Mo chacune (JPEG, PNG, WebP).`;

  for (const name of names) {
    const thumb = clone('tpl-thumb');
    thumb.querySelector('img').src = fileUrl(name);
    thumb.querySelector('button').addEventListener('click', () => {
      kept.splice(kept.indexOf(name), 1);
      thumb.remove();
    });
    thumbs.append(thumb);
  }
  return { el, read: () => kept, files: () => [...input.files] };
}

export async function render(el, ctx) {
  const isNew = ctx.param === 'new';
  const point = isNew ? null : await api('GET', `points/${ctx.param}`);
  const collectionOf = (id) => ctx.state.collections.find((c) => c.id === id);

  const form = clone('tpl-point');
  const { lat, lng, status, collection: collectionSelect } = form.elements;
  const fieldsBox = form.querySelector('[data-fields]');
  const error = form.querySelector('[data-error]');
  const back = `#/${!point || point.status !== 'pending' ? 'points' : 'moderation'}`;
  let collection = isNew ? ctx.state.collections[0] : collectionOf(point.collection);
  let fields = [];

  if (!collection) {
    throw new Error('Aucune collection n\'est définie : un compte admin peut en créer une dans la page Collections.');
  }

  fill(form, {
    title: isNew ? 'Nouveau point' : `Point n° ${point.id}`,
    meta: isNew ? '' : `${collection.name} · soumis le ${formatDate(point.created_at)}`,
  });
  for (const link of form.querySelectorAll('[data-back]')) {
    link.href = back;
  }

  const badge = form.querySelector('[data-status-badge]');
  if (isNew) {
    badge.hidden = true;
    form.querySelector('[data-delete]').hidden = true;
    for (const c of ctx.state.collections) {
      collectionSelect.add(new Option(c.name, c.id));
    }
  } else {
    statusBadge(badge, point.status);
    form.querySelector('[data-new-only]').hidden = true;
  }
  status.value = point?.status ?? 'published';

  function renderFields() {
    fields = collection.fields.map((field) => ({ field, ...buildField(field, point?.properties[field.name]) }));
    fieldsBox.replaceChildren(...fields.map((f) => f.el));
  }
  renderFields();

  collectionSelect.addEventListener('change', () => {
    collection = collectionOf(collectionSelect.value);
    renderFields();
  });

  // Position : la carte et les champs restent synchronisés.
  const position = point ?? map.center();
  const round = (value) => Math.round(value * 1e6) / 1e6;
  lat.value = round(position.lat);
  lng.value = round(position.lng);
  const moveMarker = map.editMarker(position.lat, position.lng, (latlng) => {
    lat.value = round(latlng.lat);
    lng.value = round(latlng.lng);
  });
  for (const input of [lat, lng]) {
    input.addEventListener('change', () => moveMarker(lat.valueAsNumber || 0, lng.valueAsNumber || 0));
  }
  if (point) {
    map.focus(point.lat, point.lng);
  }

  function showFieldErrors(details = {}) {
    for (const { field, el } of fields) {
      el.querySelector('[data-field-error]').textContent = details[field.name] ?? '';
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const body = new FormData();
    const properties = {};
    for (const { field, read, files } of fields) {
      properties[field.name] = read();
      for (const file of files?.() ?? []) {
        body.append(`${field.name}[]`, file);
      }
    }
    body.append('data', JSON.stringify({
      collection: collection.id,
      lat: lat.valueAsNumber,
      lng: lng.valueAsNumber,
      status: status.value,
      properties,
    }));

    busy(form, async () => {
      try {
        showError(error, null);
        showFieldErrors();
        await api('POST', isNew ? 'points' : `points/${point.id}`, body);
        await ctx.refreshCollections();
        ctx.navigate(back);
      } catch (e) {
        showError(error, e);
        showFieldErrors(e.details);
      }
    });
  });

  form.querySelector('[data-delete]').addEventListener('click', async () => {
    if (confirm('Supprimer définitivement ce point et ses images ?')) {
      await api('DELETE', `points/${point.id}`);
      await ctx.refreshCollections();
      ctx.navigate(back);
    }
  });

  el.append(form);
}

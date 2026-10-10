// Propositions de modification de points publiés, envoyées par l'API publique : examen, application ou refus.

import { api, fileUrl } from '../api.js';
import { clone, fill, formatDate, showError } from '../dom.js';
import * as map from '../map.js';
import { summary } from './list.js';

const PER_PAGE = 20;

const collectionOf = (ctx, id) => ctx.state.collections.find((c) => c.id === id);
const fieldOf = (collection, name) => collection?.fields.find((f) => f.name === name);
const labelOf = (collection, name) => fieldOf(collection, name)?.label ?? name;

/** Valeur d'un champ, lisible : texte, liste, oui/non, numéro de point ou vignettes. */
function display(field, value) {
  if (value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length)) {
    const empty = document.createElement('span');
    empty.className = 'muted';
    empty.textContent = '—';
    return empty;
  }
  if (field?.type === 'image') {
    const thumbs = document.createElement('ul');
    thumbs.className = 'thumbs';
    for (const name of value) {
      const li = document.createElement('li');
      li.className = 'thumb';
      const img = document.createElement('img');
      img.src = fileUrl(name);
      img.alt = '';
      li.append(img);
      thumbs.append(li);
    }
    return thumbs;
  }
  const text = document.createElement('span');
  text.textContent = field?.type === 'boolean' ? (value ? 'Oui' : 'Non')
    : field?.type === 'ref' ? `Point n° ${value}`
      : Array.isArray(value) ? value.join(', ') : String(value);
  return text;
}

/** Noms des champs modifiés par une proposition. */
function changedLabels(edit, collection) {
  const labels = Object.keys(edit.changes).map((name) => labelOf(collection, name));
  return edit.position ? [...labels, 'Position'] : labels;
}

export async function list(el, ctx) {
  let page = 1;
  const panel = clone('tpl-edits');
  const items = panel.querySelector('.items');

  async function load() {
    const data = await api('GET', `edits?page=${page}`);
    const pages = Math.max(1, Math.ceil(data.total / PER_PAGE));
    fill(panel, {
      total: `${data.total} proposition${data.total > 1 ? 's' : ''}`,
      page: `${page} / ${pages}`,
    });

    items.replaceChildren(...data.items.map((edit) => {
      const collection = collectionOf(ctx, edit.point.collection);
      const li = fill(clone('tpl-edit-item'), {
        title: summary(edit.point, collection),
        meta: `${collection?.name ?? edit.point.collection} · point n° ${edit.point.id} · proposée le ${formatDate(edit.created_at)}`,
        fields: `Modifie : ${changedLabels(edit, collection).join(', ')}`,
      });
      li.querySelector('[data-open]').href = `#/edits/${edit.id}`;
      li.addEventListener('click', (event) => {
        if (!event.target.closest('a')) {
          ctx.navigate(`#/edits/${edit.id}`);
        }
      });
      return li;
    }));

    panel.querySelector('[data-empty]').hidden = data.items.length > 0;
    panel.querySelector('[data-pager]').hidden = pages === 1;
    panel.querySelector('[data-prev]').disabled = page === 1;
    panel.querySelector('[data-next]').disabled = page === pages;

    const points = data.items.map((edit) => edit.point);
    map.showPoints(points, null, (point) => {
      ctx.navigate(`#/edits/${data.items.find((edit) => edit.point.id === point.id).id}`);
    });
    map.fit(points);
  }

  panel.querySelector('[data-prev]').addEventListener('click', () => { page -= 1; load(); });
  panel.querySelector('[data-next]').addEventListener('click', () => { page += 1; load(); });

  await load();
  el.append(panel);
}

export async function detail(el, ctx) {
  const edit = await api('GET', `edits/${ctx.param}`);
  const { point } = edit;
  const collection = collectionOf(ctx, point.collection);
  const panel = fill(clone('tpl-edit'), {
    title: `Modification du point n° ${point.id}`,
    meta: `${collection?.name ?? point.collection} · ${summary(point, collection)} · proposée le ${formatDate(edit.created_at)}`,
    comment: edit.comment ?? '',
  });
  panel.querySelector('[data-comment]').hidden = !edit.comment;
  panel.querySelector('[data-point]').href = `#/points/${point.id}`;

  const row = (label, before, after) => {
    const tr = document.createElement('tr');
    const th = document.createElement('th');
    th.scope = 'row';
    th.textContent = label;
    const cells = [before, after].map((node) => {
      const td = document.createElement('td');
      td.append(node);
      return td;
    });
    cells[1].className = 'diff-new';
    tr.append(th, ...cells);
    return tr;
  };

  const rows = Object.entries(edit.changes).map(([name, value]) => {
    const field = fieldOf(collection, name);
    return row(labelOf(collection, name), display(field, point.properties[name]), display(field, value));
  });
  if (edit.position) {
    const format = ([lat, lng]) => display(null, `${lat}, ${lng}`);
    rows.push(row('Position', format([point.lat, point.lng]), format(edit.position)));
  }
  panel.querySelector('tbody').append(...rows);

  panel.querySelector('[data-move]').hidden = !edit.position;

  const error = panel.querySelector('[data-error]');
  for (const [selector, action] of [['[data-apply]', 'apply'], ['[data-reject]', 'reject']]) {
    const button = panel.querySelector(selector);
    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        showError(error, null);
        await api('POST', `edits/${edit.id}/${action}`);
        await ctx.refreshCollections();
        ctx.navigate('#/edits');
      } catch (e) {
        // Proposition devenue invalide (ex. : point référencé dépublié entre-temps).
        const details = Object.entries(e.details ?? {}).map(([name, message]) => `${labelOf(collection, name)} : ${message}`);
        showError(error, { message: [e.message, ...details].join(' ') });
        button.disabled = false;
      }
    });
  }

  el.append(panel);
  // Sur grand écran, le panneau couvre la gauche de la carte : les repères sont cadrés à sa droite.
  const { right } = panel.getBoundingClientRect();
  map.showMove([point.lat, point.lng], edit.position, window.innerWidth - right > 300 ? right : 0);
}

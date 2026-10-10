// Liste des points d'un statut (file de modération, points publiés…), affichés sur la carte.

import { api } from '../api.js';
import { clone, fill, formatDate, statusBadge } from '../dom.js';
import * as map from '../map.js';

const SUMMARY_TYPES = ['select', 'text', 'number'];

/** Titre lisible d'un point : ses deux premières valeurs courtes. */
export function summary(point, collection) {
  const values = (collection?.fields ?? [])
    .filter((field) => SUMMARY_TYPES.includes(field.type))
    .map((field) => point.properties[field.name])
    .filter((value) => value !== undefined && value !== '')
    .slice(0, 2);
  return values.length ? values.join(' · ') : `Point n° ${point.id}`;
}

export async function render(el, ctx, defaultStatus, title) {
  const filters = { status: defaultStatus, collection: '', page: 1 };
  let items = [];
  let selectedId = null;

  const panel = fill(clone('tpl-list'), { title });
  const list = panel.querySelector('.items');
  const select = panel.querySelector('[name=collection]');
  const collectionOf = (id) => ctx.state.collections.find((c) => c.id === id);

  for (const collection of ctx.state.collections) {
    select.add(new Option(collection.name, collection.id));
  }

  function updateMap() {
    map.showPoints(items, selectedId, (point) => {
      choose(point.id);
      list.querySelector(`[data-id="${point.id}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  }

  function choose(id) {
    selectedId = id;
    for (const li of list.children) {
      li.classList.toggle('selected', Number(li.dataset.id) === id);
    }
    updateMap();
  }

  function renderItem(point) {
    const collection = collectionOf(point.collection);
    const li = fill(clone('tpl-list-item'), {
      title: summary(point, collection),
      meta: `${collection?.name ?? point.collection} · ${formatDate(point.created_at)}`,
    });
    li.dataset.id = point.id;
    statusBadge(li.querySelector('[data-status-badge]'), point.status);
    li.querySelector('[data-edit]').href = `#/points/${point.id}`;

    for (const button of li.querySelectorAll('[data-set-status]')) {
      button.hidden = button.dataset.setStatus === point.status;
      button.addEventListener('click', async () => {
        button.disabled = true;
        await api('POST', `points/${point.id}/status`, { status: button.dataset.setStatus });
        await Promise.all([ctx.refreshCollections(), load()]);
      });
    }
    li.addEventListener('click', (event) => {
      if (!event.target.closest('a, button')) {
        choose(point.id);
        map.focus(point.lat, point.lng);
      }
    });
    return li;
  }

  async function load(fitMap = false) {
    const params = new URLSearchParams(filters);
    const data = await api('GET', `points?${params}`);
    const pages = Math.max(1, Math.ceil(data.total / 20));
    items = data.items;

    fill(panel, {
      total: `${data.total} point${data.total > 1 ? 's' : ''}`,
      page: `${filters.page} / ${pages}`,
    });
    list.replaceChildren(...items.map(renderItem));
    panel.querySelector('[data-empty]').hidden = items.length > 0;
    panel.querySelector('[data-pager]').hidden = pages === 1;
    panel.querySelector('[data-prev]').disabled = filters.page === 1;
    panel.querySelector('[data-next]').disabled = filters.page === pages;
    for (const button of panel.querySelectorAll('[data-status]')) {
      button.classList.toggle('active', button.dataset.status === filters.status);
    }

    updateMap();
    if (fitMap) {
      map.fit(items);
    }
  }

  const reload = (changes) => {
    Object.assign(filters, changes);
    load(true);
  };

  for (const button of panel.querySelectorAll('[data-status]')) {
    button.addEventListener('click', () => reload({ status: button.dataset.status, page: 1 }));
  }
  select.addEventListener('change', () => reload({ collection: select.value, page: 1 }));
  panel.querySelector('[data-prev]').addEventListener('click', () => reload({ page: filters.page - 1 }));
  panel.querySelector('[data-next]').addEventListener('click', () => reload({ page: filters.page + 1 }));

  await load(true);
  el.append(panel);
}

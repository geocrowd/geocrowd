// Liste des collections, avec leur nombre de points ; un clic ouvre l'éditeur de schéma.

import { clone, fill } from '../dom.js';

const flag = (el, value) => {
  el.textContent = value ? 'Oui' : 'Non';
  el.className = value ? 'badge badge-yes' : 'badge';
};

export async function render(el, ctx) {
  await ctx.refreshCollections();
  const { collections, counts } = ctx.state;
  const page = clone('tpl-collections');

  const rows = collections.map((collection) => {
    const count = counts[collection.id] ?? {};
    const row = fill(clone('tpl-collection-row'), {
      name: collection.name,
      id: collection.id,
      fields: collection.fields.length,
      published: count.published ?? 0,
      pending: count.pending ?? 0,
    });
    for (const badge of row.querySelectorAll('[data-flag]')) {
      flag(badge, collection[badge.dataset.flag]);
    }
    row.addEventListener('click', () => ctx.navigate(`#/collections/${collection.id}`));
    return row;
  });

  page.querySelector('tbody').append(...rows);
  page.querySelector('.table-wrap').hidden = !rows.length;
  page.querySelector('[data-empty]').hidden = rows.length > 0;
  el.append(page);
}

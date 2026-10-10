// Clés d'accès à l'API publique, éventuellement limitées à des domaines (administrateurs uniquement).

import { api } from '../api.js';
import { busy, clone, fill, formatDate, showError } from '../dom.js';

export async function render(el) {
  const page = clone('tpl-keys');
  const tbody = page.querySelector('tbody');
  const error = page.querySelector('[data-error]');
  const form = page.querySelector('form');
  const keyError = form.querySelector('[data-key-error]');
  const keyBox = form.querySelector('[data-new-key]');
  const keyInput = keyBox.querySelector('input');

  function keyRow(key) {
    const row = fill(clone('tpl-key-row'), {
      name: key.name,
      prefix: `${key.prefix}…`,
      last: key.last_used_at ? formatDate(key.last_used_at) : 'Jamais',
    });
    const domains = row.querySelector('[data-domains]');
    if (key.domains.length) {
      domains.textContent = key.domains.join(', ');
    } else {
      domains.innerHTML = '<span class="badge badge-pending">Clé de serveur</span> <span class="muted">tous domaines, à garder secrète</span>';
    }
    row.querySelector('[data-remove]').addEventListener('click', async () => {
      if (confirm(`Révoquer la clé « ${key.name} » ? Les sites et scripts qui l'utilisent n'auront plus accès à l'API.`)) {
        try {
          showError(error, null);
          await api('DELETE', `keys/${key.id}`);
        } catch (e) {
          showError(error, e);
        }
        load();
      }
    });
    return row;
  }

  async function load() {
    const { keys, required } = await api('GET', 'keys');
    tbody.replaceChildren(...keys.map(keyRow));
    page.querySelector('[data-empty]').hidden = keys.length > 0;
    fill(page, {
      total: `${keys.length} clé${keys.length > 1 ? 's' : ''}`,
      mode: required
        ? 'L\'API publique n\'accepte que les requêtes portant une clé valide.'
        : 'Les clés ne sont pas exigées : l\'API publique est ouverte à tous. Pour les exiger, mettez \'api_key\' => true dans config.php.',
    });
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(form, async () => {
      try {
        showError(keyError, null);
        const { key } = await api('POST', 'keys', Object.fromEntries(new FormData(form)));
        keyInput.value = key;
        keyBox.hidden = false;
        keyBox.querySelector('[data-copy]').textContent = 'Copier';
        form.elements.name.value = '';
        form.elements.domains.value = '';
        await load();
      } catch (e) {
        showError(keyError, { message: e.details?.domains ?? e.details?.name ?? e.message });
      }
    });
  });

  keyBox.querySelector('[data-copy]').addEventListener('click', async (event) => {
    await navigator.clipboard.writeText(keyInput.value);
    event.target.textContent = 'Copié';
  });

  await load();
  el.append(page);
}

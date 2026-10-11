// Réglages du site public servi à la racine de l'instance (administrateurs).

import { api } from '../api.js';
import { busy, clone, fill, showError } from '../dom.js';
import * as map from '../map.js';

export async function render(el, ctx) {
  const settings = await api('GET', 'front');
  const form = clone('tpl-site');
  const error = form.querySelector('[data-error]');
  const saved = form.querySelector('[data-saved]');
  const root = new URL('../', location.href).href;
  let view = { center: settings.center, zoom: settings.zoom };

  const showView = () => fill(form, { view: `Centre ${view.center[0]}, ${view.center[1]} · zoom ${view.zoom}` });
  fill(form, { address: `Adresse : ${root}` });
  showView();
  form.querySelector('[data-open]').href = root;
  map.setView(view.center, view.zoom);

  for (const name of ['title', 'intro']) {
    form.elements[name].value = settings[name];
  }
  for (const name of ['enabled', 'submission', 'edits']) {
    form.elements[name].checked = settings[name];
  }

  const list = form.querySelector('[data-collections]');
  for (const c of ctx.state.collections) {
    const box = document.createElement('input');
    box.type = 'checkbox';
    box.value = c.id;
    box.checked = settings.collections.includes(c.id);
    const label = document.createElement('label');
    label.className = 'check';
    label.append(box, ` ${c.name}`);
    list.append(label);
  }

  form.querySelector('[data-capture]').addEventListener('click', () => {
    view = map.view();
    showView();
  });

  form.addEventListener('input', () => { saved.hidden = true; });
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    busy(form, async () => {
      try {
        showError(error, null);
        for (const slot of form.querySelectorAll('[data-error-for]')) slot.textContent = '';
        await api('PUT', 'front', {
          enabled: form.elements.enabled.checked,
          title: form.elements.title.value,
          intro: form.elements.intro.value,
          collections: [...list.querySelectorAll(':checked')].map((box) => box.value),
          submission: form.elements.submission.checked,
          edits: form.elements.edits.checked,
          center: view.center,
          zoom: view.zoom,
        });
        saved.hidden = false;
      } catch (e) {
        showError(error, e);
        for (const [name, message] of Object.entries(e.details ?? {})) {
          const slot = form.querySelector(`[data-error-for="${name}"]`);
          if (slot) slot.textContent = message;
        }
      }
    });
  });

  el.append(form);
}

// Éditeur de schéma : nom, règles d'envoi et champs d'une collection (administrateurs).

import { api } from '../api.js';
import { busy, clone, fill, showError } from '../dom.js';

export const TYPE_LABELS = {
  text: 'Texte court',
  textarea: 'Texte long',
  number: 'Nombre',
  select: 'Choix unique',
  multiselect: 'Choix multiples',
  boolean: 'Oui / non',
  date: 'Date',
  url: 'Adresse web',
  image: 'Images',
  ref: 'Lien vers un point',
};

// Valeur d'un champ « lien vers un point » qui désigne la collection en cours de création,
// dont l'identifiant peut encore changer.
const SELF = '@self';

// Options propres à chaque type, conservées dans la définition envoyée.
const TYPE_OPTIONS = {
  text: ['maxLength'],
  textarea: ['maxLength'],
  url: ['maxLength'],
  number: ['min', 'max'],
  select: ['options'],
  multiselect: ['options'],
  image: ['max', 'maxSize'],
  ref: ['collection'],
};

/** Identifiant lisible tiré d'un libellé : « Arbres remarquables » → arbres_remarquables. */
function slug(text, separator) {
  const value = text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, separator).replace(new RegExp(`^${separator}+|${separator}+$`, 'g'), '').slice(0, 50);
  return separator === '_' && /^\d/.test(value) ? `champ_${value}`.slice(0, 50) : value;
}

/** Définition envoyée à l'API : seules les options utiles au type de chaque champ. */
function definition(draft) {
  return {
    id: draft.id,
    name: draft.name,
    description: draft.description,
    moderation: draft.moderation,
    public_submission: draft.public_submission,
    public_edit: draft.public_edit,
    fields: draft.fields.map((field) => {
      const clean = { name: field.name, label: field.label, type: field.type };
      if (field.required && field.type !== 'boolean') {
        clean.required = true;
      }
      for (const key of TYPE_OPTIONS[field.type] ?? []) {
        const value = key === 'collection' && field[key] === SELF ? draft.id : field[key];
        if (value !== undefined && value !== null && value !== '') {
          clean[key] = value;
        }
      }
      return clean;
    }),
  };
}

export async function render(el, ctx) {
  const isNew = ctx.param === 'new';
  const saved = isNew ? null : ctx.state.collections.find((c) => c.id === ctx.param);
  if (!isNew && !saved) {
    throw new Error('Collection introuvable.');
  }
  const usage = saved ? await api('GET', `collections/${saved.id}/usage`) : {};

  // Brouillon modifié par le formulaire ; `saved` marque les champs déjà enregistrés.
  const draft = saved
    ? { ...structuredClone(saved), fields: saved.fields.map((f) => ({ ...structuredClone(f), saved: true })) }
    : { id: '', name: '', description: '', moderation: true, public_submission: true, public_edit: true, fields: [] };
  let idTouched = !isNew;

  const page = clone('tpl-schema');
  const form = page.querySelector('form');
  const list = form.querySelector('[data-fields]');
  const json = page.querySelector('[data-json]');
  const error = form.querySelector('[data-error]');

  fill(page, { title: isNew ? 'Nouvelle collection' : saved.name });
  form.querySelector('[data-id-hint]').textContent = isNew
    ? 'Utilisé dans les adresses de l\'API, il ne pourra plus changer.'
    : `Adresse de l'API : /api/collections/${saved.id}`;
  form.elements.id.disabled = !isNew;
  form.querySelector('[data-delete]').hidden = isNew;

  const newType = form.querySelector('[data-new-type]');
  for (const [type, label] of Object.entries(TYPE_LABELS)) {
    newType.add(new Option(label, type));
  }

  const preview = () => {
    json.textContent = JSON.stringify(definition(draft), null, 2);
    fill(form, { count: `${draft.fields.length} champ${draft.fields.length > 1 ? 's' : ''}` });
  };

  // Réglages généraux

  draft.description ??= '';
  for (const name of ['name', 'id', 'description']) {
    form.elements[name].value = draft[name];
  }
  form.elements.description.addEventListener('input', () => {
    draft.description = form.elements.description.value;
    preview();
  });
  for (const name of ['moderation', 'public_submission', 'public_edit']) {
    form.elements[name].checked = draft[name];
  }
  form.elements.name.addEventListener('input', () => {
    draft.name = form.elements.name.value;
    if (!idTouched) {
      draft.id = form.elements.id.value = slug(draft.name, '-');
    }
    preview();
  });
  form.elements.id.addEventListener('input', () => {
    idTouched = true;
    draft.id = form.elements.id.value;
    preview();
  });
  for (const name of ['moderation', 'public_submission', 'public_edit']) {
    form.elements[name].addEventListener('change', () => {
      draft[name] = form.elements[name].checked;
      preview();
    });
  }

  // Champs

  function fieldCard(field, index) {
    const card = clone('tpl-schema-field');
    const title = () => fill(card, { title: field.label || 'Nouveau champ' });
    title();

    const typeSelect = card.querySelector('[data-prop=type]');
    for (const [type, label] of Object.entries(TYPE_LABELS)) {
      typeSelect.add(new Option(label, type));
    }
    const refSelect = card.querySelector('[data-prop=collection]');
    for (const c of ctx.state.collections) {
      refSelect.add(new Option(c.name, c.id));
    }
    if (isNew) {
      refSelect.add(new Option(`${draft.name || 'Cette collection'} (elle-même)`, SELF));
    }

    // Seules les options du type courant sont affichées et lues.
    const showType = () => {
      card.querySelector('[data-type-label]').textContent = TYPE_LABELS[field.type];
      for (const section of card.querySelectorAll('[data-for]')) {
        section.hidden = !section.dataset.for.split(' ').includes(field.type);
      }
    };

    for (const input of card.querySelectorAll('[data-prop]')) {
      const prop = input.dataset.prop;
      const section = input.closest('[data-for]');
      const value = section && !section.dataset.for.split(' ').includes(field.type) ? undefined : field[prop];
      if (input.type === 'checkbox') {
        input.checked = Boolean(value);
      } else if ('lines' in input.dataset) {
        input.value = (value ?? []).join('\n');
      } else {
        input.value = value ?? '';
      }

      input.addEventListener(input.tagName === 'SELECT' || input.type === 'checkbox' ? 'change' : 'input', () => {
        if (input.type === 'checkbox') {
          field[prop] = input.checked;
        } else if ('lines' in input.dataset) {
          field[prop] = input.value.split('\n').map((line) => line.trim()).filter(Boolean);
        } else if ('int' in input.dataset || 'num' in input.dataset) {
          field[prop] = input.value === '' ? null : Number(input.value);
        } else {
          field[prop] = input.value;
        }

        if (prop === 'label') {
          title();
          if (!field.saved && !field.nameTouched) {
            field.name = card.querySelector('[data-prop=name]').value = slug(field.label, '_');
          }
        }
        if (prop === 'name') {
          field.nameTouched = true;
        }
        if (prop === 'type') {
          // Les options de l'ancien type ne s'appliquent plus.
          for (const key of Object.values(TYPE_OPTIONS).flat()) {
            delete field[key];
          }
          if (field.type === 'ref') {
            field.collection = ctx.state.collections[0]?.id ?? SELF;
          }
          renderFields();
          return;
        }
        preview();
      });
    }

    // Un champ enregistré garde son nom (clé des données) et son type.
    card.querySelector('[data-prop=name]').disabled = Boolean(field.saved);
    typeSelect.disabled = Boolean(field.saved);
    const filled = usage[field.name] ?? 0;
    card.querySelector('[data-hint]').textContent = field.saved
      ? `${filled ? `Renseigné pour ${filled} point${filled > 1 ? 's' : ''}. ` : ''}Le nom technique et le type d'un champ enregistré ne changent plus.`
      : '';

    card.querySelector('[data-move="-1"]').disabled = index === 0;
    card.querySelector('[data-move="1"]').disabled = index === draft.fields.length - 1;
    for (const button of card.querySelectorAll('[data-move]')) {
      button.addEventListener('click', () => {
        const target = index + Number(button.dataset.move);
        [draft.fields[index], draft.fields[target]] = [draft.fields[target], draft.fields[index]];
        renderFields();
      });
    }
    card.querySelector('[data-remove]').addEventListener('click', () => {
      draft.fields.splice(index, 1);
      renderFields();
    });

    showType();
    return card;
  }

  function renderFields() {
    list.replaceChildren(...draft.fields.map(fieldCard));
    preview();
  }

  form.querySelector('[data-add]').addEventListener('click', () => {
    const type = newType.value;
    draft.fields.push({ name: '', label: '', type, ...(type === 'ref' ? { collection: ctx.state.collections[0]?.id ?? SELF } : {}) });
    renderFields();
    list.lastElementChild.querySelector('[data-prop=label]').focus();
  });

  // Erreurs renvoyées par le serveur : « name », « fields.2.options »…
  function showErrors(details = {}) {
    for (const slot of form.querySelectorAll('[data-error-for]')) {
      slot.textContent = '';
    }
    for (const [path, message] of Object.entries(details)) {
      const [, index, prop] = path.match(/^fields\.(\d+)\.(\w+)$/) ?? [];
      const scope = index === undefined ? form : list.children[index];
      const slots = [...(scope?.querySelectorAll(`[data-error-for="${prop ?? path}"]`) ?? [])];
      const slot = slots.find((s) => !s.closest('[hidden]')) ?? slots[0];
      if (slot) {
        slot.textContent = message;
      }
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();

    // Champs retirés qui contiennent des données : leurs valeurs seront effacées.
    const kept = new Set(draft.fields.map((f) => f.name));
    const lost = (saved?.fields ?? []).filter((f) => !kept.has(f.name) && usage[f.name]);
    if (lost.length && !confirm(`Les valeurs de ces champs seront définitivement effacées, images comprises :\n\n${
      lost.map((f) => `• ${f.label} (${usage[f.name]} point${usage[f.name] > 1 ? 's' : ''})`).join('\n')}\n\nContinuer ?`)) {
      return;
    }

    busy(form, async () => {
      try {
        showError(error, null);
        showErrors();
        const body = definition(draft);
        await (isNew ? api('POST', 'collections', body) : api('PUT', `collections/${saved.id}`, body));
        await ctx.refreshCollections();
        ctx.navigate('#/collections');
      } catch (e) {
        showError(error, e);
        showErrors(e.details);
      }
    });
  });

  form.querySelector('[data-delete]').addEventListener('click', async () => {
    const count = Object.values(ctx.state.counts[saved.id] ?? {}).reduce((a, b) => a + b, 0);
    const message = count
      ? `Supprimer « ${saved.name} » et ${count > 1 ? `ses ${count} points` : 'son point'}, images comprises ? C'est définitif.`
      : `Supprimer « ${saved.name} » ?`;
    if (!confirm(message)) {
      return;
    }
    try {
      showError(error, null);
      await api('DELETE', `collections/${saved.id}`);
      await ctx.refreshCollections();
      ctx.navigate('#/collections');
    } catch (e) {
      showError(error, e);
    }
  });

  renderFields();
  el.append(page);
}

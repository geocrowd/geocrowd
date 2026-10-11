// Codes de secours de la double authentification, affichés une seule fois.

import { clone } from '../dom.js';

/** Liste de codes à conserver ; `onDone` est appelé quand la personne confirme les avoir gardés. */
export function recoveryCodes(codes, onDone) {
  const box = clone('tpl-recovery-codes');
  const text = `Codes de secours geocrowd (${location.host}), chacun utilisable une fois :\n\n${codes.join('\n')}\n`;

  box.querySelector('[data-codes]').append(...codes.map((code) => {
    const li = document.createElement('li');
    li.textContent = code;
    return li;
  }));

  box.querySelector('[data-copy]').addEventListener('click', async (event) => {
    await navigator.clipboard.writeText(text);
    event.target.textContent = 'Copiés';
  });

  box.querySelector('[data-download]').addEventListener('click', () => {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    link.download = 'geocrowd-codes-de-secours.txt';
    link.click();
    URL.revokeObjectURL(link.href);
  });

  const confirm = box.querySelector('[data-confirm]');
  const done = box.querySelector('[data-continue]');
  confirm.addEventListener('change', () => { done.disabled = !confirm.checked; });
  done.addEventListener('click', onDone);
  return box;
}

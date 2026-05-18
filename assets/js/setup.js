'use strict';

const dbRadios = document.querySelectorAll('.setup-db-radio');
const mysqlFields = document.getElementById('setup-mysql-fields');

function updateSetupDbChoice() {
  const selected = document.querySelector('.setup-db-radio:checked')?.value || 'sqlite';
  if (mysqlFields) mysqlFields.hidden = selected !== 'mysql';
  document.querySelectorAll('.setup-choice-card').forEach(card => {
    const input = card.closest('.setup-choice')?.querySelector('.setup-db-radio');
    card.classList.toggle('active', input?.checked);
  });
}

dbRadios.forEach(radio => radio.addEventListener('change', updateSetupDbChoice));
updateSetupDbChoice();

const setupAvatar = document.getElementById('setup-avatar');
const setupAvatarName = document.getElementById('setup-avatar-name');
setupAvatar?.addEventListener('change', () => {
  const file = setupAvatar.files && setupAvatar.files[0];
  if (setupAvatarName) setupAvatarName.textContent = file ? file.name : 'No file selected';
});

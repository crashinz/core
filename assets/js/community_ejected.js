'use strict';

const ejectionTime = document.getElementById('community-ejection-local');
if (ejectionTime?.dataset.utc) {
  const date = new Date(ejectionTime.dataset.utc.replace(' ', 'T') + 'Z');
  if (!Number.isNaN(date.getTime())) ejectionTime.textContent = date.toLocaleString();
}

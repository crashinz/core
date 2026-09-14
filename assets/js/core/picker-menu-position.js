// Menu anchors are viewport coordinates; CSS zoom scales fixed-position offsets.
export function positionPickerMenu(menu, x, y) {
  menu.style.maxWidth = '';
  menu.style.maxHeight = '';
  const scale = menu.offsetWidth ? menu.getBoundingClientRect().width / menu.offsetWidth : 1;
  const zoom = scale > 0 ? scale : 1;
  menu.style.minWidth = '0';
  menu.style.maxWidth = `${(window.innerWidth - 16) / zoom}px`;
  menu.style.maxHeight = `${(window.innerHeight - 16) / zoom}px`;
  menu.style.overflowY = 'auto';
  const rect = menu.getBoundingClientRect();
  menu.style.left = `${Math.max(8, Math.min(x, window.innerWidth - rect.width - 8)) / zoom}px`;
  menu.style.top = `${Math.max(8, Math.min(y, window.innerHeight - rect.height - 8)) / zoom}px`;
}

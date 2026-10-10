// Carte Leaflet en fond de toutes les vues.

const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '© contributeurs <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
const DEFAULT_VIEW = [[46.6, 2.4], 6];

let map;
let layer;
let clickHandler = null;

const pin = (selected) => L.divIcon({ className: selected ? 'pin pin-on' : 'pin', iconSize: selected ? [22, 22] : [14, 14] });

export function init(el) {
  map = L.map(el, { zoomControl: false }).setView(...DEFAULT_VIEW);
  L.control.zoom({ position: 'bottomright' }).addTo(map);
  L.tileLayer(TILES, { attribution: ATTRIBUTION, maxZoom: 19 }).addTo(map);
  layer = L.layerGroup().addTo(map);
  map.on('click', (e) => clickHandler?.(e.latlng));
}

export function clear() {
  layer.clearLayers();
  clickHandler = null;
}

/** Affiche une liste de points ; `onSelect(point)` est appelé au clic sur un repère. */
export function showPoints(points, selectedId, onSelect) {
  layer.clearLayers();
  for (const point of points) {
    L.marker([point.lat, point.lng], { icon: pin(point.id === selectedId) })
      .on('click', () => onSelect(point))
      .addTo(layer);
  }
}

export function fit(points) {
  if (points.length) {
    map.fitBounds(points.map((p) => [p.lat, p.lng]), { maxZoom: 16, padding: [60, 60] });
  }
}

export function focus(lat, lng) {
  map.flyTo([lat, lng], Math.max(map.getZoom(), 16));
}

/** Vue courante de la carte : [lat, lng] arrondis et niveau de zoom. */
export function view() {
  const { lat, lng } = map.getCenter();
  return { center: [Math.round(lat * 1e5) / 1e5, Math.round(lng * 1e5) / 1e5], zoom: map.getZoom() };
}

export function setView(center, zoom) {
  map.setView(center, zoom);
}

export function center() {
  const { lat, lng } = map.getCenter();
  return { lat, lng };
}

/**
 * Position actuelle d'un point et, s'il y en a une, la position proposée pour le déplacer.
 * `left` : largeur occupée par le panneau à gauche de la carte, pour garder les repères visibles.
 */
export function showMove(from, to, left = 0) {
  layer.clearLayers();
  L.marker(from, { icon: pin(false) }).addTo(layer);
  if (!to) {
    focus(...from);
    return;
  }
  L.polyline([from, to], { color: '#ff7a1a', weight: 2, dashArray: '4 6' }).addTo(layer);
  L.marker(to, { icon: pin(true) }).addTo(layer);
  map.fitBounds([from, to], { maxZoom: 18, paddingTopLeft: [left + 80, 80], paddingBottomRight: [80, 80] });
}

/** Repère déplaçable (glisser ou clic sur la carte) ; `onMove({lat, lng})` à chaque déplacement. */
export function editMarker(lat, lng, onMove) {
  layer.clearLayers();
  const marker = L.marker([lat, lng], { icon: pin(true), draggable: true }).addTo(layer);
  marker.on('dragend', () => onMove(marker.getLatLng()));
  clickHandler = (latlng) => {
    marker.setLatLng(latlng);
    onMove(latlng);
  };
  return (lat, lng) => marker.setLatLng([lat, lng]);
}

/** Centre la carte sur un lieu trouvé par Nominatim (OpenStreetMap). */
export async function search(query) {
  const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(query)}`;
  const [place] = await (await fetch(url)).json();
  if (place) {
    map.flyTo([place.lat, place.lon], 15);
  }
  return Boolean(place);
}

document.addEventListener('DOMContentLoaded', () => {
  const url = document.querySelector('input[name="avatar_url"]');
  const img = document.getElementById('avatarPreview');
  if (!url || !img) return;
  const fallback = img.getAttribute('data-fallback') || img.src;
  const apply = () => { img.src = (url.value || '').trim() || fallback; };
  url.addEventListener('input', apply);
});
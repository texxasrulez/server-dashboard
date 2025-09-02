
(function(){
  'use strict';
  var EDITABLE = /^(INPUT|TEXTAREA|SELECT)$/;
  function isEditable(el){
    if (!el) return false;
    if (EDITABLE.test(el.tagName)) return true;
    if (el.isContentEditable) return true;
    return false;
  }
  window.addEventListener('keydown', function(e){
    if (!isEditable(e.target)) return;
    var k = e.key;
    if (k === ' ' || k === 'Spacebar') { e.stopPropagation(); return; }
    if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.stopPropagation();
    }
  }, true);
})();

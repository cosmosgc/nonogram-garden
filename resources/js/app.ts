import { createApp } from 'vue';
import NonogramEditor from './components/NonogramEditor.vue';
import NonogramPlayer from './components/NonogramPlayer.vue';
import NonogramImageImporter from './components/NonogramImageImporter.vue';

const editor = document.getElementById('nonogram-editor');
if (editor) {
    createApp(NonogramEditor).mount(editor);
}

const importer = document.getElementById('nonogram-image-importer');
if (importer) {
    createApp(NonogramImageImporter).mount(importer);
}

const player = document.getElementById('nonogram-player');
if (player) {
    createApp(NonogramPlayer).mount(player);
}

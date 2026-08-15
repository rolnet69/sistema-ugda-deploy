import './bootstrap';
import { createApp } from 'vue';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import ToastService from 'primevue/toastservice';
import router from './router';
import App from './components/App.vue';
import { installInputSanitizer } from './inputSanitizer';

// 1. ESTILOS DE ICONOS
import 'primeicons/primeicons.css';

// 2. ESTILOS DE LAYOUT (¡ESTO ES LO QUE FALTA!)
// Sin esto, 'col-12', 'md:col-6', 'grid' no funcionan y el modal se ve roto.
import 'primeflex/primeflex.css'; 

import axios from 'axios';

// Componentes
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import MultiSelect from 'primevue/multiselect';
import ToggleSwitch from 'primevue/toggleswitch';
import Toast from 'primevue/toast';
import Card from 'primevue/card';
import DatePicker from 'primevue/datepicker';

// Interceptor para sesión expirada
axios.interceptors.response.use(
    response => response,
    error => {
        if (error.response && (error.response.status === 419 || error.response.status === 401)) {
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    }
);

const app = createApp(App);

app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: '.fake-dark-mode', 
        }
    },
    // --- AQUÍ AGREGAMOS LA TRADUCCIÓN AL ESPAÑOL ---
    locale: 
    {
        dayNames: ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"],
        dayNamesShort: ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"],
        dayNamesMin: ["D", "L", "M", "X", "J", "V", "S"],
        monthNames: ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"],
        monthNamesShort: ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"],
        today: 'Hoy',
        clear: 'Limpiar',
        weekHeader: 'Sm',
        firstDayOfWeek: 1, // 1 = Lunes, 0 = Domingo
        dateFormat: 'dd/mm/yy',
        weak: 'Débil',
        medium: 'Medio',
        strong: 'Fuerte',
        passwordPrompt: 'Escriba una contraseña',
        emptyFilterMessage: 'No se encontraron resultados.', // Tablas y listas filtradas
        emptyMessage: 'No hay opciones disponibles.', // Dropdowns y selectores
        searchMessage: 'Buscar',
        selectionMessage: 'Seleccionado {0}',
        emptySelectionMessage: 'No hay ninguna opción seleccionada'
    }
});

app.use(ToastService);
app.use(router);

// Registrar Componentes
app.component('Button', Button);
app.component('InputText', InputText);
app.component('DataTable', DataTable);
app.component('Column', Column);
app.component('Dialog', Dialog);
app.component('Dropdown', Dropdown);
app.component('MultiSelect', MultiSelect);
app.component('ToggleSwitch', ToggleSwitch);
app.component('Toast', Toast);
app.component('Card', Card);
app.component('DatePicker', DatePicker);

installInputSanitizer();

app.mount('#app');

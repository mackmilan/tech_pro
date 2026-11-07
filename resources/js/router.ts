import { createRouter, createWebHistory } from 'vue-router';
import Dashboard from './pages/Dashboard.vue';
import ModulePage from './pages/ModulePage.vue';
import CategoriesPage from './pages/CategoriesPage.vue';
import BrandsPage from './pages/BrandsPage.vue';
import SuppliersPage from './pages/SuppliersPage.vue';
import ProductConditionsPage from './pages/ProductConditionsPage.vue';
import ProductsPage from './pages/ProductsPage.vue';
import ProductFormPage from './pages/ProductFormPage.vue';
import ProductAttributesPage from './pages/ProductAttributesPage.vue';
import BranchesPage from './pages/BranchesPage.vue';
import StockPage from './pages/StockPage.vue';
import StockEntryPage from './pages/StockEntryPage.vue';
import StockLocationsPage from './pages/StockLocationsPage.vue';
import PurchaseOrdersPage from './pages/PurchaseOrdersPage.vue';
import PurchaseOrderFormPage from './pages/PurchaseOrderFormPage.vue';
import PurchaseOrderReceivePage from './pages/PurchaseOrderReceivePage.vue';
import ServiceOrdersPage from './pages/ServiceOrdersPage.vue';
import ServiceOrderFormPage from './pages/ServiceOrderFormPage.vue';
import ServicesPage from './pages/ServicesPage.vue';
import ChecklistsPage from './pages/ChecklistsPage.vue';
import TechniciansPage from './pages/TechniciansPage.vue';
import CustomersPage from './pages/CustomersPage.vue';
import ServiceOrderStatusRulesPage from './pages/ServiceOrderStatusRulesPage.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'dashboard', component: Dashboard },
        { path: '/crm/ordens-de-servico', name: 'service-orders', component: ServiceOrdersPage },
        { path: '/crm/ordens-de-servico/nova', name: 'service-orders.create', component: ServiceOrderFormPage },
        { path: '/crm/ordens-de-servico/:id/editar', name: 'service-orders.edit', component: ServiceOrderFormPage },
        { path: '/crm/servicos', name: 'services', component: ServicesPage },
        { path: '/crm/checklists', name: 'checklists', component: ChecklistsPage },
        { path: '/crm/tecnicos', name: 'technicians', component: TechniciansPage },
        { path: '/crm/clientes', name: 'customers', component: CustomersPage },
        { path: '/erp/categorias', name: 'categories', component: CategoriesPage },
        { path: '/erp/marcas', name: 'brands', component: BrandsPage },
        { path: '/erp/fornecedores', name: 'suppliers', component: SuppliersPage },
        { path: '/erp/condicoes-produto', name: 'product-conditions', component: ProductConditionsPage },
        { path: '/erp/produtos', name: 'products', component: ProductsPage },
        { path: '/erp/produtos/novo', name: 'products.create', component: ProductFormPage },
        { path: '/erp/produtos/:id/editar', name: 'products.edit', component: ProductFormPage },
        { path: '/erp/atributos-produto', name: 'product-attributes', component: ProductAttributesPage },
        { path: '/erp/compras', name: 'purchase-orders', component: PurchaseOrdersPage },
        { path: '/erp/compras/novo', name: 'purchase-orders.create', component: PurchaseOrderFormPage },
        { path: '/erp/compras/:id/editar', name: 'purchase-orders.edit', component: PurchaseOrderFormPage },
        { path: '/erp/compras/:id/receber', name: 'purchase-orders.receive', component: PurchaseOrderReceivePage },
        { path: '/configuracoes/filiais', name: 'branches', component: BranchesPage },
        { path: '/configuracoes/status-ordem-servico', name: 'service-order-status-rules', component: ServiceOrderStatusRulesPage },
        { path: '/erp/estoque', name: 'stock', component: StockPage },
        { path: '/erp/estoque/novo-lancamento', name: 'stock.entry', component: StockEntryPage },
        { path: '/erp/localizacoes', name: 'stock-locations', component: StockLocationsPage },
        { path: '/:module(crm|erp|caixa|configuracoes)/:pathMatch(.*)*', name: 'module-subpath', component: ModulePage },
        { path: '/:module(crm|erp|caixa|configuracoes)', name: 'module', component: ModulePage },
    ],
});

export default router;

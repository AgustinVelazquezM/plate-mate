const API = {
    baseUrl: window.location.origin + '/plate-mate/api/',
    token: localStorage.getItem('token') || localStorage.getItem('restauranteToken') || localStorage.getItem('clienteToken'),

    setToken(token) {
        this.token = token;
        localStorage.setItem('token', token);
    },

    clearToken() {
        this.token = null;
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        localStorage.removeItem('restauranteToken');
        localStorage.removeItem('restauranteUser');
        localStorage.removeItem('clienteToken');
        localStorage.removeItem('clienteUser');
    },

    async request(endpoint, method = 'GET', data = null) {
        const url = this.baseUrl + endpoint;
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': this.token ? `Bearer ${this.token}` : ''
            }
        };
        
        if (data && (method === 'POST' || method === 'PUT')) {
            options.body = JSON.stringify(data);
        }
        
        try {
            const response = await fetch(url, options);
            
            if (response.status === 401) {
                this.clearToken();
                window.location.href = 'login.html';
                throw new Error('Sesión expirada');
            }
            
            // Obtener la respuesta como texto primero para mejor depuración
            const text = await response.text();
            
            // Verificar si la respuesta está vacía
            if (!text || text.trim() === '') {
                throw new Error('El servidor devolvió una respuesta vacía');
            }
            
            // Intentar parsear como JSON
            let result;
            try {
                result = JSON.parse(text);
            } catch (e) {
                console.error('❌ Error parsing JSON:', text.substring(0, 500));
                throw new Error('Error al procesar la respuesta del servidor: ' + text.substring(0, 100));
            }
            
            if (!response.ok) {
                throw new Error(result.error || 'Error en la solicitud');
            }
            
            return result;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },

    async uploadFile(file) {
        const url = this.baseUrl + 'upload.php';
        const formData = new FormData();
        formData.append('image', file);
        
        const options = {
            method: 'POST',
            headers: {
                'Authorization': this.token ? `Bearer ${this.token}` : ''
            },
            body: formData
        };
        
        try {
            const response = await fetch(url, options);
            
            if (response.status === 401) {
                this.clearToken();
                window.location.href = 'login.html';
                throw new Error('Sesión expirada');
            }
            
            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (e) {
                throw new Error('Error al procesar la respuesta');
            }
            
            if (!response.ok) {
                throw new Error(result.error || 'Error al subir la imagen');
            }
            
            return result;
        } catch (error) {
            console.error('Upload Error:', error);
            throw error;
        }
    },

    // Auth Restaurante
    async login(email, password) {
        const result = await this.request('auth.php', 'POST', { action: 'login', email, password });
        if (result.success) {
            this.setToken(result.token);
            localStorage.setItem('restauranteUser', JSON.stringify(result.user));
        }
        return result;
    },

    async register(userData) {
        const result = await this.request('auth.php', 'POST', { action: 'register', ...userData });
        if (result.success) {
            this.setToken(result.token);
            localStorage.setItem('restauranteUser', JSON.stringify(result.user));
        }
        return result;
    },

    // Auth Cliente
    async clienteLogin(email, password) {
        const result = await this.request('clientes_auth.php', 'POST', { action: 'login', email, password });
        if (result.success) {
            this.setToken(result.token);
            localStorage.setItem('clienteUser', JSON.stringify(result.user));
        }
        return result;
    },

    async clienteRegister(userData) {
        const result = await this.request('clientes_auth.php', 'POST', { action: 'register', ...userData });
        if (result.success) {
            this.setToken(result.token);
            localStorage.setItem('clienteUser', JSON.stringify(result.user));
        }
        return result;
    },

    async logout() {
        try {
            await this.request('auth.php', 'POST', { action: 'logout' });
        } finally {
            this.clearToken();
        }
    },

    // Menus
    async getMenus() { 
        return await this.request('menus.php'); 
    },
    
    async getMenu(id) { 
        return await this.request(`menus.php?id=${id}`); 
    },
    
    async createMenu(menuData) { 
        return await this.request('menus.php', 'POST', menuData); 
    },
    
    async updateMenu(id, menuData) { 
        return await this.request('menus.php', 'PUT', { id, ...menuData }); 
    },
    
    async deleteMenu(id) { 
        return await this.request(`menus.php?id=${id}`, 'DELETE'); 
    },

    // Categories
    async getCategories(menuId = null) {
        const url = menuId ? `categories.php?menu_id=${menuId}` : 'categories.php';
        return await this.request(url);
    },
    
    async getCategory(id) { 
        return await this.request(`categories.php?id=${id}`); 
    },
    
    async createCategory(categoryData) { 
        return await this.request('categories.php', 'POST', categoryData); 
    },
    
    async updateCategory(id, categoryData) { 
        return await this.request('categories.php', 'PUT', { id, ...categoryData }); 
    },
    
    async deleteCategory(id) { 
        return await this.request(`categories.php?id=${id}`, 'DELETE'); 
    },

    // Items
    async getItems(categoryId = null, search = null, status = null) {
        let url = 'items.php?';
        const params = [];
        if (categoryId) params.push(`category_id=${categoryId}`);
        if (search) params.push(`search=${encodeURIComponent(search)}`);
        if (status) params.push(`status=${status}`);
        url += params.length > 0 ? params.join('&') : '';
        return await this.request(url);
    },
    
    async getItem(id) { 
        return await this.request(`items.php?id=${id}`); 
    },
    
    async createItem(itemData) { 
        return await this.request('items.php', 'POST', itemData); 
    },
    
    async updateItem(id, itemData) { 
        return await this.request('items.php', 'PUT', { id, ...itemData }); 
    },
    
    async deleteItem(id) { 
        return await this.request(`items.php?id=${id}`, 'DELETE'); 
    },

 async getOrders(params = {}) {
    const queryString = new URLSearchParams(params).toString();
    const url = `orders.php${queryString ? `?${queryString}` : ''}`;
    return await this.request(url);
},
    
    async getOrder(id) { 
        return await this.request(`orders.php?id=${id}`); 
    },
    
    async createOrder(orderData) { 
        console.log('📦 API.createOrder - Enviando:', orderData);
        const result = await this.request('orders.php', 'POST', orderData);
        console.log('📨 API.createOrder - Respuesta:', result);
        return result; 
    },
    
    async updateOrder(id, orderData) { 
        return await this.request('orders.php', 'PUT', { id, ...orderData }); 
    },

    // Promotions
    async getPromotions(activeOnly = false) {
        const url = activeOnly ? 'promotions.php?active=true' : 'promotions.php';
        return await this.request(url);
    },
    
    async getPromotion(id) { 
        return await this.request(`promotions.php?id=${id}`); 
    },
    
    async createPromotion(promotionData) { 
        return await this.request('promotions.php', 'POST', promotionData); 
    },
    
    async updatePromotion(id, promotionData) { 
        return await this.request('promotions.php', 'PUT', { id, ...promotionData }); 
    },
    
    async deletePromotion(id) { 
        return await this.request(`promotions.php?id=${id}`, 'DELETE'); 
    },

    // Analytics
    async getDashboardStats() { 
        return await this.request('analytics.php?type=dashboard'); 
    },
    
    async getPopularItems(limit = 10) { 
        return await this.request(`analytics.php?type=popular&limit=${limit}`); 
    },

    // Allergens
    async getAllergens() {
        return [
            { id: 1, nombre: 'Gluten' },
            { id: 2, nombre: 'Lactosa' },
            { id: 3, nombre: 'Huevo' },
            { id: 4, nombre: 'Pescado' },
            { id: 5, nombre: 'Mariscos' },
            { id: 6, nombre: 'Frutos secos' },
            { id: 7, nombre: 'Cacahuetes' },
            { id: 8, nombre: 'Soja' },
            { id: 9, nombre: 'Apio' },
            { id: 10, nombre: 'Mostaza' },
            { id: 11, nombre: 'Sésamo' },
            { id: 12, nombre: 'Sulfitos' },
            { id: 13, nombre: 'Altramuces' },
            { id: 14, nombre: 'Moluscos' }
        ];
    }
};

window.API = API;
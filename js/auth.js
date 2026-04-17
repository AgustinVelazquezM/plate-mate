document.addEventListener('DOMContentLoaded', function() {
    const mainTabs = document.querySelectorAll('.main-tab');
    const authTabs = document.querySelectorAll('.auth-tab');
    const forms = document.querySelectorAll('.auth-form');
    const sections = document.querySelectorAll('.auth-section');
    
    // Configurar tabs principales (Restaurante/Cliente)
    mainTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const mainId = this.getAttribute('data-main');
            
            mainTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            sections.forEach(section => {
                section.classList.remove('active');
                if (section.id === mainId + 'Section') {
                    section.classList.add('active');
                }
            });
            
            // Reset a login tab
            const activeSection = document.getElementById(mainId + 'Section');
            const loginTab = activeSection.querySelector('.auth-tab[data-tab^="login"]');
            if (loginTab) loginTab.click();
        });
    });
    
    // Configurar tabs de login/register
    authTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const container = this.closest('.auth-section');
            const tabId = this.getAttribute('data-tab');
            
            container.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            container.querySelectorAll('.auth-form').forEach(form => {
                form.classList.remove('active');
                if (form.id === tabId.replace('-', '') + 'Form' || 
                    form.id === tabId.split('-')[0] + capitalize(tabId.split('-')[1]) + 'Form') {
                    form.classList.add('active');
                }
            });
        });
    });
    
    // Configurar switches entre login y register
    setupSwitches();
    
    // Configurar toggles de contraseña
    setupAllToggles();
    
    // Check URL params
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('register') === 'true') {
        const mainTab = urlParams.get('type') === 'cliente' ? 'cliente' : 'restaurante';
        const mainTabBtn = document.querySelector(`.main-tab[data-main="${mainTab}"]`);
        if (mainTabBtn) mainTabBtn.click();
        
        const registerTab = document.querySelector(`#${mainTab}Section .auth-tab[data-tab="register-${mainTab}"]`);
        if (registerTab) registerTab.click();
    }
});

function capitalize(string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
}

function setupSwitches() {
    // Restaurante switches
    document.querySelectorAll('.switch-to-login-restaurante').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const tab = document.querySelector('#restauranteSection .auth-tab[data-tab="login-restaurante"]');
            if (tab) tab.click();
        });
    });
    
    document.querySelectorAll('.switch-to-register-restaurante').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const tab = document.querySelector('#restauranteSection .auth-tab[data-tab="register-restaurante"]');
            if (tab) tab.click();
        });
    });
    
    // Cliente switches
    document.querySelectorAll('.switch-to-login-cliente').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const tab = document.querySelector('#clienteSection .auth-tab[data-tab="login-cliente"]');
            if (tab) tab.click();
        });
    });
    
    document.querySelectorAll('.switch-to-register-cliente').forEach(link => {
        link.addEventListener('click', e => {
            e.preventDefault();
            const tab = document.querySelector('#clienteSection .auth-tab[data-tab="register-cliente"]');
            if (tab) tab.click();
        });
    });
}

function setupToggle(buttonId, inputId) {
    const btn = document.getElementById(buttonId);
    const input = document.getElementById(inputId);
    
    if (!btn || !input) return;
    
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const icon = this.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) icon.className = 'far fa-eye-slash';
        } else {
            input.type = 'password';
            if (icon) icon.className = 'far fa-eye';
        }
    });
}

function setupAllToggles() {
    setupToggle('toggleLoginRestaurantePassword', 'loginRestaurantePassword');
    setupToggle('toggleRegisterRestaurantePassword', 'registerRestaurantePassword');
    setupToggle('toggleRegisterRestauranteConfirmPassword', 'registerRestauranteConfirmPassword');
    setupToggle('toggleLoginClientePassword', 'loginClientePassword');
    setupToggle('toggleRegisterClientePassword', 'registerClientePassword');
    setupToggle('toggleRegisterClienteConfirmPassword', 'registerClienteConfirmPassword');
    setupToggle('toggleNewPassword', 'newPassword');
    setupToggle('toggleConfirmNewPassword', 'confirmNewPassword');
}

function showAlert(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i> ${message}`;
    
    alertDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        min-width: 300px;
        padding: 16px 20px;
        border-radius: 8px;
        background: white;
        border-left: 4px solid ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.style.animation = 'slideOut 0.3s ease forwards';
        setTimeout(() => alertDiv.remove(), 300);
    }, 3000);
}

function checkAuth() {
    const restauranteUser = localStorage.getItem('restauranteUser');
    const clienteUser = localStorage.getItem('clienteUser');
    const token = localStorage.getItem('token');
    
    if ((!restauranteUser && !clienteUser) || !token) {
        if (!window.location.href.includes('login.html') && !window.location.href.includes('index.html')) {
            window.location.href = 'login.html';
        }
        return null;
    }
    
    return restauranteUser ? JSON.parse(restauranteUser) : JSON.parse(clienteUser);
}

function logout() {
    if (confirm('¿Cerrar sesión?')) {
        localStorage.clear();
        window.location.href = 'login.html';
    }
}

// Exportar funciones globales
window.showAlert = showAlert;
window.checkAuth = checkAuth;
window.logout = logout;
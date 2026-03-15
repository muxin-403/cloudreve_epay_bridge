(() => {
  const { createApp } = Vue;

  function createLoginApp() {
    createApp({
      data() {
        return {
          state: window.__ADMIN_LOGIN_STATE__ || { cashierName: '', loginError: '' },
          password: '',
          submitting: false,
          clientError: '',
        };
      },
      methods: {
        handleSubmit(event) {
          this.clientError = '';
          if (!this.password) {
            this.clientError = '请输入管理员密码';
            event.preventDefault();
            return;
          }

          this.submitting = true;
        },
      },
    }).mount('#admin-login-app');
  }

  function createDashboardApp() {
    createApp({
      data() {
        return {
          state: window.__ADMIN_STATE__ || {
            stats: {
              totalOrders: 0,
              paidOrders: 0,
              pendingOrders: 0,
              formattedTotalAmount: '0.00',
            },
            messages: { success: '', error: '' },
            links: { config: 'config_manager.php', logout: 'admin.php?logout=1' },
          },
          filters: {
            status: '',
            search: '',
            limit: 20,
          },
          currentPage: 1,
          orders: [],
          pagination: {
            current_page: 1,
            total_pages: 1,
            total_items: 0,
            per_page: 20,
            has_prev: false,
            has_next: false,
          },
          loadingOrders: false,
          loadError: '',
          cleaning: false,
          runtimeMessage: {
            type: 'success',
            text: '',
          },
          searchTimer: null,
        };
      },
      computed: {
        orderCountText() {
          if (this.loadingOrders) {
            return '加载中...';
          }
          if (this.loadError) {
            return '加载失败';
          }
          return `共 ${this.pagination.total_items || 0} 条订单`;
        },
        pageNumbers() {
          const totalPages = this.pagination.total_pages || 1;
          const current = this.pagination.current_page || 1;
          const maxVisible = 5;

          let start = Math.max(1, current - Math.floor(maxVisible / 2));
          let end = Math.min(totalPages, start + maxVisible - 1);

          if (end - start + 1 < maxVisible) {
            start = Math.max(1, end - maxVisible + 1);
          }

          const pages = [];
          for (let i = start; i <= end; i += 1) {
            pages.push(i);
          }
          return pages;
        },
      },
      mounted() {
        this.loadOrders();
      },
      methods: {
        setRuntimeMessage(type, text) {
          this.runtimeMessage.type = type;
          this.runtimeMessage.text = text;

          if (text) {
            window.setTimeout(() => {
              this.runtimeMessage.text = '';
            }, 3000);
          }
        },
        onFilterChanged() {
          this.currentPage = 1;
          this.loadOrders();
        },
        onSearchChanged() {
          window.clearTimeout(this.searchTimer);
          this.searchTimer = window.setTimeout(() => {
            this.currentPage = 1;
            this.loadOrders();
          }, 450);
        },
        goToPage(page) {
          if (page === this.currentPage) {
            return;
          }
          this.currentPage = page;
          this.loadOrders();
        },
        previousPage() {
          if (this.pagination.has_prev) {
            this.currentPage -= 1;
            this.loadOrders();
          }
        },
        nextPage() {
          if (this.pagination.has_next) {
            this.currentPage += 1;
            this.loadOrders();
          }
        },
        async requestJson(url, options = {}) {
          const response = await fetch(url, options);

          if (response.status === 401) {
            window.location.href = 'admin.php';
            return null;
          }

          let payload = null;
          try {
            payload = await response.json();
          } catch (error) {
            throw new Error('服务端响应不是有效 JSON');
          }

          if (!response.ok) {
            throw new Error(payload.error || `请求失败 (${response.status})`);
          }

          return payload;
        },
        async loadOrders() {
          if (this.loadingOrders) {
            return;
          }

          this.loadingOrders = true;
          this.loadError = '';

          const params = new URLSearchParams({
            action: 'get_orders',
            page: String(this.currentPage),
            limit: String(this.filters.limit),
            status: this.filters.status,
            search: this.filters.search,
          });

          try {
            const data = await this.requestJson(`admin_api.php?${params.toString()}`);
            if (!data) {
              return;
            }

            if (!data.success) {
              throw new Error(data.error || '加载订单失败');
            }

            this.orders = Array.isArray(data.data.orders) ? data.data.orders : [];
            this.pagination = data.data.pagination || this.pagination;
            this.currentPage = this.pagination.current_page || this.currentPage;
          } catch (error) {
            this.loadError = error.message;
            this.orders = [];
          } finally {
            this.loadingOrders = false;
          }
        },
        async loadStats() {
          try {
            const data = await this.requestJson('admin_api.php?action=get_stats');
            if (!data || !data.success) {
              return;
            }

            this.state.stats.totalOrders = data.data.total_orders;
            this.state.stats.paidOrders = data.data.paid_orders;
            this.state.stats.pendingOrders = data.data.pending_orders;
            this.state.stats.formattedTotalAmount = data.data.formatted_total_amount;
          } catch (error) {
            this.setRuntimeMessage('error', `统计刷新失败：${error.message}`);
          }
        },
        async refreshAll() {
          await this.loadOrders();
          await this.loadStats();
          if (!this.loadError) {
            this.setRuntimeMessage('success', '数据已刷新');
          }
        },
        async cleanExpiredOrders() {
          const shouldClean = window.confirm('确定要清理过期订单吗？');
          if (!shouldClean) {
            return;
          }

          this.cleaning = true;
          try {
            const data = await this.requestJson('admin_api.php?action=clean_expired', {
              method: 'POST',
            });

            if (!data || !data.success) {
              throw new Error((data && data.error) || '清理失败');
            }

            this.setRuntimeMessage('success', data.message || '过期订单已清理');
            await this.refreshAll();
          } catch (error) {
            this.setRuntimeMessage('error', `清理失败：${error.message}`);
          } finally {
            this.cleaning = false;
          }
        },
      },
    }).mount('#admin-dashboard-app');
  }

  if (window.__ADMIN_PAGE__ === 'login') {
    createLoginApp();
    return;
  }

  if (window.__ADMIN_PAGE__ === 'dashboard') {
    createDashboardApp();
  }
})();

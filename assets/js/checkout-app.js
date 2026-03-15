(() => {
  const { createApp } = Vue;

  createApp({
    data() {
      const state = window.__CHECKOUT_STATE__ || {
        payments: [],
        showPaymentMethods: false,
        supportedPaymentNames: [],
        recommendedPayment: '',
      };

      return {
        state,
        selectedPayment: '',
      };
    },
    computed: {
      supportedPaymentNamesText() {
        if (!Array.isArray(this.state.supportedPaymentNames) || this.state.supportedPaymentNames.length === 0) {
          return '暂无可用支付方式';
        }
        return this.state.supportedPaymentNames.join('、');
      },
    },
    mounted() {
      if (!this.state.showPaymentMethods || !Array.isArray(this.state.payments) || this.state.payments.length === 0) {
        return;
      }

      const recommendedExists = this.state.payments.some((item) => item.type === this.state.recommendedPayment);
      if (recommendedExists) {
        this.selectedPayment = this.state.recommendedPayment;
      } else {
        this.selectedPayment = this.state.payments[0].type;
      }
    },
    methods: {
      selectPayment(type) {
        this.selectedPayment = type;
      },
      resolveIconType(icon) {
        if (typeof icon !== 'string' || icon.trim() === '') {
          return 'text';
        }

        const value = icon.trim();

        // Allow pre-configured SVG/icon HTML from admin config.
        if (/<[a-z][\s\S]*>/i.test(value)) {
          return 'html';
        }

        if (/^[a-z0-9_-]+(?:\s+[a-z0-9_-]+)+$/i.test(value) || /^fa[srbld]?\s+/i.test(value)) {
          return 'class';
        }

        return 'text';
      },
      processPayment() {
        if (!this.selectedPayment) {
          window.alert('请选择支付方式');
          return;
        }

        const params = new URLSearchParams({
          order_no: this.state.order.orderNo,
          payment_type: this.selectedPayment,
        });

        window.location.href = `${this.state.redirectBaseUrl}?${params.toString()}`;
      },
    },
  }).mount('#checkout-app');
})();

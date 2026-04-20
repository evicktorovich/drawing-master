<template>
    <div>
        <div v-show="isVisible" class="modal fade show" tabindex="-1" role="dialog" style="display: block;">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="modal-title fs-5 text-center text-lg-start" id="staticBackdropLabel">
                            <span v-if="type === 'event'">Sign up for the art class</span>
                            <span v-else>Sign up for the regular classes</span>
                        </div>
                        <button type="button" class="btn-close" @click="closeModal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="modal-body-description mb-4" v-html="selectedEvent.modalDescription">
                        </div>
                        <div class="modal-body-information d-flex align-items-center gap-2">
                            <img src="assets/img/icon-date.svg" alt="date">
                            Date: {{ getFormattedEventDateForPayload(selectedEvent.date, selectedEvent.day) }}
                        </div>
                        <div class="modal-body-information d-flex align-items-center gap-2">
                            <img src="assets/img/icon_time.svg" alt="time">
                            Time: {{ selectedEvent.time }}
                        </div>
                        <div class="modal-body-information  d-flex align-items-center gap-2 mb-0">
                            <img src="assets/img/icon-location.svg" alt="location">
                            Location: {{ selectedEvent.location }}
                        </div>
                        <div class="modal-body-title">What's Included:</div>
                        <div class="modal-body-description">
                            All painting supplies provided <span v-if="type === 'event'">+ snacks and drinks</span><span
                                v-else></span> for absolute relaxation and immersion in
                            the friendly atmosphere of creativity
                        </div>
                        <div class="modal-body-description gap-2 d-flex align-items-center">
                            <img src="assets/img/icon-price.svg" alt="price">
                            Price: {{ selectedEvent.price }} $ per person {{ selectedEvent.modalDiscount }}
                        </div>
                        <div class="modal-body-description mb-0">
                            {{ selectedEvent.modalIncludes }}
                        </div>
                        <form class="d-flex flex-column" @submit.prevent="submitOrder">
                            <div class="row">
                                <div class="col-12 col-lg-6 d-flex flex-column">
                                    <label for="name" class="modal-body-label">
                                        Name<span class="required">*</span>
                                    </label>
                                    <input type="text" id="name" v-model="name" class="modal-body-input" required />
                                </div>
                                <div class="col-12 col-lg-6 d-flex flex-column">
                                    <label for="phone" class="modal-body-label">
                                        Phone Number<span class="required">*</span>
                                    </label>
                                    <input type="number" id="phone" v-model="phone" class="modal-body-input" required />
                                </div>
                            </div>
                            <label for="email" class="modal-body-label">
                                Email<span class="required">*</span>
                            </label>
                            <input type="email" id="email" v-model="email" class="modal-body-input" required />
                            <label for="message" class="modal-body-label">
                                Add Your Message<span class="required">*</span>
                            </label>
                            <textarea id="message" v-model="message" class="modal-body-input modal-body-input-text"
                                required></textarea>
                            <div class="form-check custom-check">
                                <input class="form-check-input" type="checkbox" id="subscribe" v-model="subscribe">
                                <label class="form-check-label" for="subscribe">
                                    Subscribe to updates and new events
                                </label>
                            </div>
                            <div class="d-flex justify-content-center flex-column align-items-center mt-4">
                                <button type="submit" :disabled="isButtonDisabled && loading"
                                    class="modal-body-button">Proceed to check out</button>
                                <div class="cancellation-wrapper mt-3">
                                    <button type="button" class="cancellation-btn">Cancellation Policy</button>
                                    <div class="cancellation-tooltip">
                                        <p>
                                            At Shuhai Art Studio, we strive to create a welcoming and well-prepared
                                            experience for every participant. To ensure fairness and to cover the
                                            costs involved in preparing each class, the following cancellation
                                            policy applies:
                                        </p>
                                        <ul>
                                            <li>
                                                Cancellations or rescheduling requests made 48 hours or more before
                                                the workshop will receive a refund minus 20% to cover materials and
                                                administrative expenses.
                                            </li>
                                            <li>
                                                Cancellations or rescheduling requests made within 48 hours of the
                                                workshop will result in 50% of the payment being retained, as
                                                materials and studio preparation have already been arranged.
                                            </li>
                                            <li>
                                                Same-day cancellations, no-shows, or rescheduling requests on the
                                                day of the workshop are non-refundable, as your reserved spot and
                                                materials cannot be reassigned.
                                            </li>
                                        </ul>
                                        <p>
                                            Please note that payment processing fees are non-refundable.
                                        </p>
                                        <p class="mb-0">
                                            We appreciate your understanding and continued support of our studio.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="isVisible" class="modal-backdrop fade show" @click="closeModal"></div>
    </div>
</template>

<script>
import { EventBus } from '@/eventBus';
import { events, infinityEvent } from "@/events.js";
import moment from "moment/moment.js";
import { loadStripe } from '@stripe/stripe-js';

const STRIPE_PK = 'pk_live_51R8BnjHFbZBBzIhnPo2Qvr4XZbDlvFZPpcLCSEpybRIuJb3ZF9HBDm3cSGoqF4kbqWfjgiw3yQYKcXqo5jcgdAax00YHBr5HdI';
const API_ENDPOINTS = {
    CHECKOUT: '/api/create-checkout-session'
};
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content;

export default {
    name: 'OrderModal',
    data() {
        return {
            name: '',
            phone: '',
            email: '',
            message: '',
            subscribe: false,
            isVisible: false,
            events: events,
            infinityEvent: infinityEvent,
            loading: false,
            selectedEvent: {},
            type: "",
            stripe: null,
            paymentCompleted: false,
            securityNonce: Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15),
            timestamp: Date.now()
        }
    },
    computed: {
        isButtonDisabled() {
            return this.name.trim() === '' ||
                this.email.trim() === '' ||
                !this.validateEmail(this.email) ||
                this.message.trim() === '';
        },
    },
    methods: {
        validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },
        sanitizeInput(text) {
            return text.replace(/<[^>]*>?/gm, '');
        },
        getFormattedEventDateForPayload(date, day) {
            if (date === '%') {
                const cleanDay = day ? day.replace(/,\s*$/, '') : '';
                return cleanDay ? `Every ${cleanDay}` : 'Every day';
            }
            const cleanDay = day ? day.replace(/,\s*$/, '') : '';
            return cleanDay ? `${moment(date).format("MMMM D")} (${cleanDay})` : moment(date).format("MMMM D");
        },
        openModal(id, type) {
            this.type = type;
            this.selectedEvent = type === "event"
                ? this.events.find(event => event.id === id)
                : this.infinityEvent.find(event => event.id === id);
            document.body.style.overflow = 'hidden';
            this.isVisible = true;
        },
        closeModal() {
            if (this.paymentCompleted) {
                window.location.href = '/thank-you';
            } else {
                this.isVisible = false;
                document.body.style.overflow = '';
                this.resetForm();
            }
        },
        resetForm() {
            this.name = '';
            this.phone = '';
            this.email = '';
            this.message = '';
            this.paymentCompleted = false;
        },
        async submitOrder() {
            if (this.isButtonDisabled) return;

            this.loading = true;

            try {

                const sessionResponse = await this.createPaymentSession();

                if (!this.stripe || !sessionResponse?.sessionId) {
                    throw new Error('Payment system error');
                }

                const result = await this.stripe.redirectToCheckout({
                    sessionId: sessionResponse.sessionId
                });

                if (result.error) {
                    throw new Error(result.error.message);
                }

            } catch (error) {
                console.error('Payment error:', error);
                EventBus.$emit('show-notification', {
                    type: 'error',
                    message: error.message.replace(/<\/?[^>]+(>|$)/g, "")
                });
            } finally {
                this.loading = false;
            }
        },
        async createPaymentSession() {
            const payload = {
                price: this.selectedEvent.price,
                eventId: this.selectedEvent.id,
                eventName: this.sanitizeInput(this.selectedEvent.eventName),
                eventDate: this.getFormattedEventDateForPayload(this.selectedEvent.date, this.selectedEvent.day),
                eventTime: this.selectedEvent.time,
                eventLocation: this.sanitizeInput(this.selectedEvent.location),
                name: this.sanitizeInput(this.name),
                email: this.sanitizeInput(this.email),
                phone: this.sanitizeInput(this.phone),
                message: this.sanitizeInput(this.message),
                subscribe: this.subscribe,
                securityNonce: this.securityNonce,
                timestamp: this.timestamp
            };

            const response = await fetch(API_ENDPOINTS.CHECKOUT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                    'X-Request-Nonce': this.securityNonce
                },
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.message || 'Payment session error');
            }

            return await response.json();
        }
    },
    async mounted() {
        try {
            this.stripe = await loadStripe(STRIPE_PK, {
                betas: ['checkout_beta_4'],
                locale: 'en'
            });

            EventBus.$on('openOrderModal', (id, type) => {
                this.openModal(id, type);
            });

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('session_id')) {
                this.paymentCompleted = true;
                this.closeModal();

                const cleanUrl = window.location.origin + window.location.pathname;
                window.history.replaceState({}, document.title, cleanUrl);
            }
        } catch (error) {
            console.error('Stripe initialization error:', error);
            EventBus.$emit('show-notification', {
                type: 'error',
                message: 'Payment system is currently unavailable'
            });
        }
    },
    beforeDestroy() {
        EventBus.$off('openOrderModal');
    }
};
</script>


<style scoped lang="less">
::v-deep .modal-body-description-bold {
    font-size: 20px;
    font-weight: 500;
    line-height: 145%;
    margin-bottom: 10px;
    margin-top: 12px;
}


.modal {
    &-dialog {
        max-width: 800px;
    }

    &-header {
        border: none;
    }

    &-header {
        position: relative;
        display: flex;
        justify-content: center;
        padding: 50px 0 0 0;

        @media (max-width: 991px) {
            padding: 60px 0 0 0;
        }
    }

    .btn-close {
        position: absolute;
        right: 27px;
        top: 27px;
    }

    &-title {
        font-family: Cormorant Garamond, serif;
        font-size: 42px !important;
        font-weight: 400;
        line-height: 115%;
        margin-bottom: 25px;

        @media (max-width: 991px) {
            font-size: 36px !important;
            margin-bottom: 20px;
        }
    }

    &-body {
        padding: 0 40px 50px 40px;

        @media (max-width: 991px) {
            padding: 0 15px 60px 15px;
        }

        &-title {
            font-size: 20px;
            font-weight: 500;
            line-height: 150%;
            margin-top: 20px;
            margin-bottom: 10px;

            @media (max-width: 991px) {
                font-size: 18px;
                margin-bottom: 10px;
            }
        }

        &-description,
        &-information {
            font-family: Montserrat, serif;
            font-size: 15px;
            font-weight: 400;
            line-height: 150%;
            margin-bottom: 7px;

            @media (max-width: 991px) {
                font-size: 16px;
                margin-bottom: 8px;
            }
        }

        &-description {
            margin-bottom: 10px;

            @media (max-width: 991px) {
                margin-bottom: 20px;
            }
        }

        &-label {
            font-family: Montserrat, serif;
            font-size: 14px;
            font-weight: 600;
            line-height: 17px;
            margin-bottom: 10px;
        }

        &-input {
            font-family: Montserrat, serif;
            border: 1px solid rgb(185, 185, 185);
            border-radius: 50px;
            height: 50px;
            margin-bottom: 20px;
            padding: 0 20px;

            &-text {
                font-family: Montserrat, serif;
                padding: 3px 20px;
                height: 100px;
                border-radius: 35px;
                resize: none;
            }
        }

        &-button {
            font-family: Montserrat, serif;
            border-radius: 80px;
            border: unset;
            width: 280px;
            height: 60px;
            background: rgb(255, 183, 133);
            font-size: 22px;
            font-weight: 500;
            line-height: 120%;

            &:disabled {
                opacity: 50%;
            }
        }

        form {
            margin-top: 40px;
        }
    }

    ::v-deep .form-check {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 20px;
        padding: 0;
    }

    ::v-deep .form-check-input {
        width: 20px;
        height: 20px;
        border-radius: 5px;
        border: 1px solid #999;
        cursor: pointer;
        margin: 0;
        padding: 0;
    }

    ::v-deep .form-check-label {
        font-family: Montserrat, sans-serif;
        font-size: 16px;
    }

}

.cancellation-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
}

.cancellation-btn {
    font-family: Montserrat, serif;
    font-size: 14px;
    font-weight: 500;
    background: transparent;
    border: none;
    color: #007bff;
    cursor: pointer;
    padding: 0;
}

.cancellation-btn:hover {
    text-decoration: underline;
}

.cancellation-tooltip {
    display: none;
    position: absolute;
    bottom: 120%;
    left: 50%;
    transform: translateX(-50%);
    min-width: 288px;
    width: 100%;
    max-width: 500px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 12px;
    padding: 15px;
    font-size: 14px;
    line-height: 1.5;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 100;
}

.cancellation-wrapper:hover .cancellation-tooltip {
    display: block;
}
</style>

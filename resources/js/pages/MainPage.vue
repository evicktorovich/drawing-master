<template>
    <main class="main">
        <div class="banner mb-200">
            <HeaderComponent></HeaderComponent>
            <div class="container d-flex align-items-center flex-column">
                <div class="banner-title" style="white-space:pre-line">{{ siteText.bannerQuote }}</div>
                <div class="banner-description">
                    {{ siteText.bannerAuthor }}
                </div>
                <a href="#events" class="main-button">
                    View events
                </a>
            </div>
        </div>
        <div class="container">
            <div class="why mb-200 d-flex align-items-center flex-column">
                <div class="main-title" style="white-space:pre-line">{{ siteText.whyTitle }}</div>
                <div class="d-flex flex-column flex-lg-row">
                    <div class="why-block">
                        <div class="why-block-title">{{ siteText.whyLeftTitle }}</div>
                        <div v-for="(item, i) in siteText.whyLeftItems" :key="'wl'+i"
                             class="why-block-description"
                             :class="{ 'mb-0': i === siteText.whyLeftItems.length - 1 }">
                            <img src="assets/img/icon-checkmark.png" alt="checkmark">
                            {{ item }}
                        </div>
                    </div>
                    <div class="why-block me-0">
                        <div class="why-block-title">{{ siteText.whyRightTitle }}</div>
                        <div v-for="(item, i) in siteText.whyRightItems" :key="'wr'+i"
                             class="why-block-description"
                             :class="{ 'mb-0': i === siteText.whyRightItems.length - 1 }">
                            <img src="assets/img/icon-checkmark.png" alt="checkmark">
                            {{ item }}
                        </div>
                    </div>
                </div>
                <a href="#contact" class="main-button">Contact us</a>
            </div>
            <div class="events mb-200" id="events">
                <div class="main-title">Upcoming events</div>
                <div class="f-carousel mb-5" id="eventsContainer">
                    <div class="f-carousel__viewport">
                        <div class="f-carousel__track">
                            <div class="f-carousel__slide" v-for="event in events" v-if="expiredEvent(event.date)">
                                <div class="events-block ">
                                    <img :src="'assets/img/'+ event.img" alt="event.img" class="events-block-img" loading="lazy">
                                    <div class="events-block-container d-flex flex-column justify-content-between">
                                        <div class="events-block-container-content">
                                            <div class="events-block-title">{{event.eventName}}</div>
                                            <div class="events-block-information d-flex align-items-center">
                                                <img src="assets/img/icon-date.svg" alt="date">
                                                {{event.day}} {{event.startDate}} {{ getFormatedDate(event.date) }},  {{event.time}}
                                            </div>
                                            <div class="events-block-information d-flex align-items-center">
                                                <img src="assets/img/icon-price.svg" alt="price">
                                                ${{event.price}}
                                            </div>
                                            <div class="events-block-information d-flex align-items-center mb-0">
                                                <img src="assets/img/icon-location.svg" alt="location">
                                                {{event.location}}
                                            </div>
                                            <div class="events-block-description">{{event.description}}</div>
                                        </div>
                                        <button class="main-button" @click="openEventModal(event.id, 'event')">Sign up</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="events mb-200" id="infinity-events">
                <div class="main-title">REGULAR ART CLASSES</div>
                <div class="f-carousel" id="infinityEvents">
                    <div class="f-carousel__viewport">
                        <div class="f-carousel__track">
                            <div class="f-carousel__slide" v-for="infinityEvent in infinityEvent">
                                <div class="events-block ">
                                    <img :src="'assets/img/'+ infinityEvent.img" alt="event.img" class="events-block-img">
                                    <div class="events-block-container d-flex flex-column justify-content-between">
                                        <div class="events-block-container-content">
                                            <div class="events-block-title">{{infinityEvent.eventName}}</div>
                                            <div class="events-block-information d-flex align-items-center">
                                                <img src="assets/img/icon-date.svg" alt="date">
                                                {{ getFormatedDate(infinityEvent.date) }} {{infinityEvent.day}},  {{infinityEvent.time}}
                                            </div>
                                            <div class="events-block-information d-flex align-items-center">
                                                <img src="assets/img/icon-price.svg" alt="price">
                                                ${{infinityEvent.price }}
                                            </div>
                                            <div class="events-block-information d-flex align-items-center mb-0">
                                                <img src="assets/img/icon-location.svg" alt="location">
                                                {{infinityEvent.location}}
                                            </div>
                                            <div class="events-block-description">{{infinityEvent.description}}</div>
                                        </div>
                                        <button class="main-button" @click="openEventModal(infinityEvent.id, 'infinityEvent')">Sign up</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="gallery d-flex align-items-center flex-column mb-200">
                <div class="main-title">GALLERY</div>
                <div class="btn-group flex-column flex-lg-row" role="group" aria-label="Basic checkbox toggle button group">
                    <button
                        class="gallery-btn btn btn-outline-primary"
                        :class="{ active: studentWorks }"
                        @click="studentWorks = !studentWorks"
                    >
                        student artwork
                    </button>
                    <button
                        class="gallery-btn btn btn-outline-primary"
                        :class="{ active: !studentWorks }"
                        @click="studentWorks = !studentWorks"
                    >
                        my artwork
                    </button>
                </div>
                <div v-if="studentWorks">
                    <Fancybox
                        :options="{
                            Carousel: {
                              infinite: false,
                            },
                         }"
                    >
                        <div class="gallery-wrapper">
                            <a v-for="(img, index) in images.student" class="gallery-item" data-fancybox="gallery-students" :href="img">
                                <img
                                    :src="img"
                                    :alt="'students-paintings'+index"
                                    class="adaptive-img"
                                    loading="lazy"
                                />
                            </a>
                        </div>
                    </Fancybox>
                </div>
                <div v-else>
                    <Fancybox
                        :options="{
                            Carousel: {
                              infinite: false,
                            },
                         }"
                    >
                        <div class="gallery-wrapper">
                            <a v-for="(img, index) in images.my" class="gallery-item" data-fancybox="gallery-my" :href="img">
                                <img
                                    :src="img"
                                    :alt="'my-paintings'+index"
                                    class="adaptive-img"
                                    loading="lazy"
                                />
                            </a>
                        </div>
                    </Fancybox>
                </div>
            </div>
            <div class="about mb-200">
                <div class="row align-items-center">
                    <div class="col-12 col-lg-6 mb-4 mb-lg-0 text-center text-lg-start">
                        <div class="main-title text-center text-lg-start">{{ siteText.aboutTitle }}</div>
                        <div v-for="(p, i) in siteText.aboutParagraphs" :key="'ap'+i" class="about-description">
                            {{ p }}
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <img src="assets/img/photo-about-the-author.png" alt="photo-about-the-author"
                             class="adaptive-img" loading="lazy">
                    </div>
                </div>
            </div>
        </div>
        <div class="subscribe mb-200 d-flex align-items-center">
            <div class="container">
                <div class="row">
                    <div class="col-12 col-lg-9 d-flex justify-content-center mb-3 mb-lg-0">
                        <div class="subscribe-title">{{ siteText.subscribeQuote }}</div>
                    </div>
                    <div class="col-12 col-lg-3 d-flex justify-content-center">
                        <a href="#events" class="main-button">Events</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="container">
            <div class="message mb-200" id="contact">
                <div class="row flex-column-reverse flex-lg-row justify-content-between">
                    <div class="col-12 col-lg-6">
                        <img src="assets/img/paint-photo.webp" alt="paint-photo" class="adaptive-img" loading="lazy">
                    </div>
                    <div class="col-12 col-lg-5 mb-5 mb-lg-0">
                        <div class="main-title text-center text-lg-start">Let’s get in touch</div>
                        <ContactFormComponent/>
                    </div>
                </div>
            </div>
            <div class="faq mb-200">
                <div class="row">
                    <div class="col-12 col-lg-6">
                        <div class="main-title text-center mb-4 mb-lg-0 text-lg-start">Frequently asked questions</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="accordion" id="accordionExample">
                            <div v-for="(item, i) in siteText.faq" :key="'faq'+i"
                                 class="accordion-item" :class="{ 'mb-0': i === siteText.faq.length - 1 }">
                                <h2 class="accordion-header">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                            :class="{ collapsed: i !== 0 }"
                                            :data-bs-target="'#faqCollapse'+i"
                                            :aria-expanded="i === 0 ? 'true' : 'false'"
                                            :aria-controls="'faqCollapse'+i">
                                        {{ item.question }}
                                    </button>
                                </h2>
                                <div :id="'faqCollapse'+i" class="accordion-collapse collapse"
                                     :class="{ show: i === 0 }"
                                     data-bs-parent="#accordionExample">
                                    <div class="accordion-body">{{ item.answer }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <OrderModal ref="productModal"/>
    </main>
</template>

<script>
import moment from "moment";
import {events, infinityEvent} from "@/events.js";
import HeaderComponent from "@/components/HeaderComponent.vue";
import {Carousel} from "@fancyapps/ui/dist/carousel/carousel.esm.js";
import {Autoplay} from "@fancyapps/ui/dist/carousel/carousel.autoplay.esm.js";
import {EventBus} from "@/eventBus.js";
import OrderModal from "@/components/OrderModal.vue";

import Fancybox from "@/components/FancyBox.vue";
import ContactFormComponent from "@/components/ContactFormComponent.vue";

export default {
    name: "MainPage",
    components: {
        ContactFormComponent,
        Fancybox,
        HeaderComponent,
        OrderModal,
    },
    data() {
        return {
            events: events,
            infinityEvent: infinityEvent,
            studentWorks: true,
            form: {
                name: "",
                email: "",
                message: "",
            },
            images: {
                student: [
                    "assets/img/gallery/student_artwork/IMG_1819.webp",
                    "assets/img/gallery/student_artwork/IMG_1014.webp",
                    "assets/img/gallery/student_artwork/IMG_0552.webp",
                    "assets/img/gallery/student_artwork/IMG_3963.webp",
                ],
                my : [
                    "assets/img/gallery/my_artwork/IMG_1469.webp",
                    "assets/img/gallery/my_artwork/IMG_7640.webp",
                    "assets/img/gallery/my_artwork/IMG_5363.webp",
                    "assets/img/gallery/my_artwork/IMG_3931.webp",
                ]
            },
            siteText: {
                bannerQuote: "“Art washes away from the\nsoul the dust\nof everyday life”",
                bannerAuthor: "Pablo Picasso",
                whyTitle: "Let’s get creative!\nArt classes for all",
                whyLeftTitle: "Visual arts & creative classes",
                whyLeftItems: [
                    "individual lessons",
                    "group workshops for children and adults",
                    "corporate events",
                    "courses in the visual arts for all levels of training and much more",
                ],
                whyRightTitle: "Why me?",
                whyRightItems: [
                    "professional experience teaching fine arts for over 12 years",
                    "friendly and inspiring atmosphere at the classes",
                    "provision of high quality materials",
                    "individual approach to each student",
                    "flexible schedule",
                    "convenient location",
                ],
                aboutTitle: "About me",
                aboutParagraphs: [
                    "Good to see you here! My name is Alevtyna, I am a professional fine arts teacher based in Calgary. I am continuously inspired by world we live in and can never resist putting this beauty on a canvas.",
                    "My preferred art style is realism and I have academic knowledge and practical experience of applying it in painting, drawing, and compositions. Along with my students I work on creating portraits, landscapes, architecture stills, and a lot more.",
                    "However, I’m always open to exploring new styles, approaches, and techniques. I believe that there are limitless possibilities when it comes to art and I want to inspire my students to experiment and express themselves.",
                    "Let’s get creative!",
                ],
                subscribeQuote: "“Escape the Ordinary – Paint Your Dreams!”",
                faq: [
                    {question: "What if I don’t have any experience with painting?", answer: "Don’t worry! I will find an individual approach to each student based on their level of experience."},
                    {question: "Do I need to bring my own materials or supplies ?", answer: "All materials and supplies are included in the cost of the workshop. The high quality of the materials provided is guaranteed."},
                    {question: "What if I can't complete the work in the allotted time or draw it faster?", answer: "The average length of the class is 3 hours, but of course students can work at different paces. I will monitor the process so that you have a finished work. The remaining time can be spent socializing with other students, having drinks and snacks)."},
                ],
            },
        };
    },
    computed: {
        isFormValid() {
            return this.form.name.trim() !== "" &&
                this.form.email.trim() !== "" &&
                this.form.message.trim() !== "";
        },
    },
    methods: {
        getFormatedDate(date) {
            if (date === "%") {
                return "Every"
            }
            return moment(date).format("MMMM D");
        },
        expiredEvent(date) {
            const eventDate = moment(date, "YYYY-MM-DD");
            const today = moment().startOf("day");
            return !eventDate.isBefore(today);
        },
        openEventModal(id, type) {
            this.$refs.productModal.openModal(id, type);
        },
        sendContactForm() {

        }
    },
    mounted() {
        const initCarousels = () => {
            const eventsContainer = document.getElementById("events");
            const infinityEventsContainer = document.getElementById("infinityEvents");
            const options = {
                slidesPerPage: 2,
                center: false,
                Autoplay: { timeout: 3000 },
            };
            new Carousel(eventsContainer, options, {Autoplay});
            new Carousel(infinityEventsContainer, options, {Autoplay});
        };

        // Fetch CMS content (managed via /admin). Falls back to hardcoded defaults if unavailable.
        const url = `/content.json?ts=${Math.floor(Date.now() / 60000)}`; // bust 1-min cache
        fetch(url)
            .then(r => (r.ok ? r.json() : null))
            .then(cms => {
                if (!cms) return;
                if (Array.isArray(cms.events)) this.events = cms.events;
                if (Array.isArray(cms.regularClasses)) this.infinityEvent = cms.regularClasses;
                if (cms.gallery) {
                    if (Array.isArray(cms.gallery.student)) this.images.student = cms.gallery.student;
                    if (Array.isArray(cms.gallery.my)) this.images.my = cms.gallery.my;
                }
                if (cms.siteText) Object.assign(this.siteText, cms.siteText);
            })
            .catch(() => {})
            .finally(() => this.$nextTick(initCarousels));
    }
};
</script>

<style scoped lang="less">
    @import "@fancyapps/ui/dist/carousel/carousel.autoplay.css";
    .f-carousel {
        --f-carousel-slide-width: calc((100% - 40px) / 2);
        --f-carousel-spacing: 40px;
        --f-carousel-dot-color: rgb(255, 141, 60) !important;
        --f-carousel-dot-height: 12px;
        --f-carousel-dot-width: 12px;
        --f-button-prev-pos: -85px;
        --f-button-next-pos: -85px;
        --f-button-width: 45px;
        --f-button-height: 45px;
        --f-button-shadow: 0 4px 20px 0 rgba(0, 0, 0, 0.1);
        --f-button-border-radius: 50%;



        @media (max-width: 991px) {
            --f-carousel-slide-width: 100%;
            --f-carousel-spacing: 30px;
        }

        &__viewport {
            padding: 0 15px 15px 15px;
        }

        &-slide {
            margin: -20px;
        }
        ::v-deep .f-progress {
            display: none!important;
        }
    }
    ::v-deep .f-button {
        @media (max-width: 1110px) {
            display: none;
        }

    }

    .main-title {
        font-family: "Cormorant Garamond", serif;
        font-size: 70px;
        font-weight: 700;
        line-height: 105%;
        text-align: center;
        text-transform: uppercase;
        @media (max-width: 991px) {
            font-size: 45px;
        }
    }
    .main-button {
        width: 280px;
        height: 60px;
        border-radius: 80px;
        background: rgb(255, 183, 133);
        color: rgb(0, 0, 0);
        font-family: Montserrat, serif;
        font-size: 22px;
        font-weight: 500;
        line-height: 120%;
        border: none;
        display: flex;
        justify-content: center;
        align-items: center;
        text-decoration: none;

        @media (max-width: 991px) {
            width: 220px;
            height: 45px;
            font-size: 18px;
        }

        &:hover {
            background: #FFA769;
        }
    }



    .banner {
        background: url("assets/img/bg-main-banner.webp") center bottom no-repeat;
        background-size: cover;
        height: 700px;
        @media (max-width: 991px) {
            background: url("assets/img/bg-mobile-banner.webp") center bottom no-repeat;
            background-size: cover;
            height: 520px;
        }

        &-title {
            color: rgb(255, 255, 255);
            margin-top: 77px;
            margin-bottom: 21px;
            font-family: "Cormorant Garamond", serif;
            font-size: 72px;
            font-weight: 700;
            line-height: 100%;
            text-align: center;
            text-transform: uppercase;
            font-style: italic;
            @media (max-width: 991px) {
                font-size: 38px;
                margin-top: 40px;
                margin-bottom: 15px;
            }
        }
        &-description {
            color: rgb(255, 255, 255);
            font-family: Montserrat, serif;
            font-size: 30px;
            font-weight: 400;
            line-height: 120%;
            margin-bottom: 40px;
            @media (max-width: 991px) {
                font-size: 18px;
                margin-bottom: 30px;
            }
        }
    }
    .why {
        &-block {
            margin-top: 50px;
            margin-right: 40px;
            border-radius: 50px;
            box-shadow: 0 4px 20px 0 rgba(0, 0, 0, 0.1);
            background: rgb(255, 255, 255);
            padding: 40px 30px;
            margin-bottom: 30px;

            @media (max-width: 991px) {
                padding: 35px 20px;
                margin-top: 30px;
                margin-right: 0;
                margin-bottom: 25px;
                border-radius: 40px;
            }

            &:last-child {
                @media (max-width: 991px) {
                    margin-top: 0;
                }
            }

            &-title {
                font-family: Montserrat, serif;
                font-size: 20px;
                font-weight: 600;
                line-height: 145%;
                margin-bottom: 25px;
                @media (max-width: 991px) {
                    font-size: 18px;
                    margin-bottom: 20px;
                }
            }
            &-description {
                font-family: Montserrat, serif;
                font-size: 20px;
                font-weight: 400;
                line-height: 150%;
                display: flex;
                gap: 10px;
                align-items: start;
                margin-bottom: 15px;

                @media (max-width: 991px) {
                    gap: 6px;
                    font-size: 16px;
                    margin-bottom: 12px;
                }

                img {
                    @media (max-width: 991px) {
                        width: 24px;
                        height: 24px;
                    }
                }
            }
        }
    }
    .events {
        .main-title {
            margin-bottom: 50px;

            @media (max-width: 991px) {
                margin-bottom: 30px;
            }
        }
        &-block {
            border-radius: 50px;
            box-shadow: 0 4px 20px 0 rgba(0, 0, 0, 0.1);
            background: rgb(255, 255, 255);

            @media (max-width: 991px) {
                border-radius: 40px;
            }


            &-img {
                object-fit: cover;
                height: 400px;
                width: 100%;
                border-radius: 50px 50px 0 0;
            }

            &-container {
                padding: 30px 30px 40px 30px;

                @media (max-width: 991px) {
                    padding: 20px 15px 35px 15px;
                }

                &-content {
                    @media (max-width: 767px) {
                        min-height: 330px;
                    }

                    @media (max-width: 370px) {
                        min-height: 360px;
                    }
                }

                .main-button {
                    width: unset;
                }
            }
            &-title {
                font-family: Cormorant Garamond, serif;
                font-size: 36px;
                font-weight: 400;
                line-height: 115%;
                margin-bottom: 20px;
                @media (max-width: 991px) {
                    font-size: 26px;
                }
            }
            &-description, &-information {
                font-family: Montserrat, serif;
                font-size: 15px;
                font-weight: 400;
                line-height: 150%;
                margin-bottom: 7px;

                img {
                    margin-right: 8px;
                }
            }
            &-description {
                margin-bottom: 30px;
                margin-top: 15px;
            }
        }
    }
    .gallery {
        &-wrapper {
            display: grid;
            grid-template-areas:
            "photo1 photo2 photo3"
            "photo4 photo4 photo3";
            gap: 25px;
            grid-template-columns: 1fr 1fr 1fr;

            @media (max-width: 991px) {
                grid-template-areas:
                "photo1"
                "photo2"
                "photo4"
                "photo3"
                "photo3";
                grid-template-columns: 1fr;
            }
        }

        &-item {
            border-radius: 50px;
            overflow: hidden;
        }

        &-item:nth-child(1) {
            grid-area: photo1;

            img {
                height: 292px;
                object-fit: cover;
            }
        }

        &-item:nth-child(2) {
            grid-area: photo2;

            img {
                height: 292px;
                object-fit: cover;
            }
        }

        &-item:nth-child(3) {
            grid-area: photo3;

            img {
                height: 100%;
                object-fit: cover;
            }
        }

        &-item:nth-child(4) {
            grid-area: photo4;

            img {
                height: 292px;
                object-fit: cover;
            }

        }
        &-btn {
            margin-top: 50px;
            margin-bottom: 30px;
            background: transparent;
            color: rgb(0, 0, 0) !important;
            font-family: Montserrat, serif;
            font-size: 22px;
            font-weight: 400;
            line-height: 110%;
            padding: unset;
            width: 239px;
            height: 45px;
            display: flex;
            justify-content: center;
            align-items: center;
            border: 1px solid rgb(255, 201, 139);
            transition: background-color 0.3s ease, color 0.3s ease;

            @media (max-width: 991px) {
                width: 290px;
                height: 45px;
                margin-top: 30px;
                margin-bottom: 12px;
            }

            &:active {
                background: #FFA769;
                border: none;
            }

            &.active {
                background: rgb(255, 201, 139) !important;
                border: none;
            }

            &:first-of-type {
                border-radius: 90px 0 0 90px;
                @media (max-width: 991px) {
                    border-radius: 90px !important;
                }
            }

            &:last-of-type {
                border-radius: 0 90px 90px 0;
                @media (max-width: 991px) {
                    border-radius: 90px;
                    margin-top: 0;
                    margin-bottom: 30px;
                }
            }
        }
    }
    .about {
        .main-title {
            margin-bottom: 30px;
            text-align: left;
        }
        &-description {
            font-family: Montserrat, serif;
            font-size: 17px;
            font-weight: 400;
            line-height: 150%;
            margin-bottom: 15px;

            @media (max-width: 991px) {
                font-size: 16px;
                margin-bottom: 12px;
            }
        }
    }
    .subscribe {
        background: url("assets/img/subscribe-to-the-newsletter.png") center no-repeat;
        background-size: cover;
        height: 227px;

        .main-button {
            width: 250px;
            height: 50px;
        }

        &-title {
            font-family: Cormorant Garamond, serif;
            font-size: 47px;
            font-weight: 700;
            line-height: 90%;

            @media (max-width: 991px) {
                font-size: 38px;
                text-align: center;
            }
        }
        &-input {
            border: 1px solid rgb(255, 183, 133);
            border-radius: 50px;
            background: rgb(255, 255, 255);
            color: rgb(89, 89, 89);
            font-family: Montserrat, serif;
            font-size: 16px;
            font-weight: 500;
            line-height: 20px;
            padding: 16px 26px 16px 16px;
            &:focus {
                box-shadow: none;
                z-index: 1;
            }
        }
        &-button {
            color: black;
            border: none;
            right: 25px;
            border-radius: 50px !important;
            background: rgb(255, 183, 133);
            font-family: Montserrat, serif;
            font-size: 22px;
            font-weight: 500;
            line-height: 120%;
            padding: 15px 68px;
        }
    }
    .faq {
        .main-title {
            text-align: left;
        }
        .accordion-item, .accordion-header, .accordion-button {
            background: rgb(255, 183, 133);
            border-radius: 40px !important;
            border: none !important;
            box-shadow: none;
        }
        .collapsed {
            border-radius: 40px !important;
            border: none !important;
            box-shadow: 0 4px 20px 0 rgba(0, 0, 0, 0.1);
            background: rgb(255, 255, 255);
            padding: 40px 35px !important;

        }
        .accordion-button {
            font-family: Cormorant Garamond, serif;
            font-size: 34px !important;
            font-weight: 400 !important;
            line-height: 100% !important;
            padding: 40px 35px 0 35px;
            color: black;

            @media (max-width: 991px) {
                font-size: 30px;
            }
        }
        .accordion-body {
            font-family: Montserrat, serif;
            font-size: 17px;
            font-weight: 400;
            line-height: 140%;
            padding: 40px 35px;
            color: black;

            @media (max-width: 991px) {
                font-size: 16px;
            }
        }
        .accordion-item {
            margin-bottom: 30px;

            @media (max-width: 991px) {
                margin-bottom: 25px;
            }
        }
    }
</style>

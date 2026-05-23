<template>
    <footer class="footer">
        <div>
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d552.8726910502835!2d-114.0929368359429!3d51.04345265867562!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x53716fdc213102af%3A0x46c983738ba5eecf!2zMTMyNCAxMSBBdmUgU1csIENhbGdhcnksIEFCIFQzQyAwTTYsINCa0LDQvdCw0LTQsA!5e0!3m2!1suk!2ssk!4v1735054037375!5m2!1suk!2ssk"
                    style="border:0;"
                    allowfullscreen=""
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade">
            </iframe>
        </div>
        <div class="footer-content">
            <div class="col-12 col-md-6 d-flex flex-column  justify-content-end">
                <div class="footer-title">
                    Contacts
                </div>
                <div class="footer-contact">
                    <img src="assets/img/icon-email.svg" alt="icon-email">
                    {{ email }}
                </div>
                <div class="footer-contact mb-0">
                    <img src="assets/img/icon-location-black.svg" alt="icon-location-footer">
                    {{ address }}
                </div>
                <div class="footer-description">
                    Follow us on social media
                </div>
                <div>
                    <a href="https://www.instagram.com/shuhai_art_studio/" target="_blank">
                        <img src="assets/img/icon-instagram.svg" alt="logo-instagram">
                    </a>
                    <a href="https://www.facebook.com/people/ART-Shuhai/61562003400987/" target="_blank">
                        <img src="assets/img/icon-facebook.svg" alt="logo-facebook">
                    </a>
                </div>
            </div>
        </div>
    </footer>

</template>

<script>
export default {
    name: 'FooterComponent',
    data() {
        return {
            email: "a.art.shuhai@gmail.com",
            address: "1324 11 Ave, SW, #202, Calgary",
        };
    },
    mounted() {
        fetch(`/content.json?ts=${Math.floor(Date.now() / 60000)}`)
            .then(r => (r.ok ? r.json() : null))
            .then(cms => {
                if (cms?.siteText?.footerEmail) this.email = cms.siteText.footerEmail;
                if (cms?.siteText?.footerAddress) this.address = cms.siteText.footerAddress;
            })
            .catch(() => {});
    },
};
</script>

<style scoped lang="less">
    .footer {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: 1fr 1fr;
        color: black;

        @media (max-width: 768px) {
           display: flex;
            flex-direction: column-reverse;
        }

        &:after {
            content: '';
            position: absolute;
            left: 0;
            top: -2px;
            height: 127px;
            width: 100%;
            background: url("assets/img/footer-bg.png") center no-repeat;
            background-size: cover;
            z-index: 2;

            @media (max-width: 768px) {
                background: url("assets/img/footer-bg-mobile.png") center no-repeat;
                background-size: cover;
                height: 66px;
            }
        }

        iframe {
            width: 100%;
            height: 100%;

            @media (max-width: 768px) {
                height: 320px;
            }
        }

        &-content {
            padding: 160px 0 40px 70px;
            background: #FFB785;

            @media (max-width: 768px) {
                padding: 96px 0 30px 15px;
            }
        }

        &-title {
            font-family: Cormorant Garamond, serif;
            font-size: 50px;
            font-weight: 400;
            line-height: 115%;
            margin-bottom: 36px;
        }
        &-contact {
            font-family: Montserrat, serif;
            font-size: 16px;
            font-weight: 500;
            line-height: 140%;
            margin-bottom: 13px;

            img {
                margin-right: 8px;
            }
        }
        &-description {
            font-family: Cormorant Garamond, serif;
            font-size: 22px;
            font-weight: 400;
            line-height: 140%;
            margin-top: 22px;
            margin-bottom: 18px;
        }
        a {
            margin-right: 20px;
        }
    }
</style>

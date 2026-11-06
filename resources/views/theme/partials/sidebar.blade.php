<aside class="pe-app-sidebar" id="sidebar">
    <div class="pe-app-sidebar-logo px-6 d-flex align-items-center position-relative">
        <!--begin::Brand Image-->
        <a href="index" class="d-flex align-items-end logo-main">
            <img height="35" width="34" class="logo-dark" alt="Dark Logo" src="assets/images/logo-md.png">
            <img height="35" width="34" class="logo-light" alt="Light Logo" src="assets/images/logo-md-light.png">
            <h3 class="text-body-emphasis fw-bolder mb-0 ms-1">Urbix</h3>
        </a>
        <button type="button" id="sidebarDefaultArrow" class="btn btn-sm p-0 fs-16 text-body-emphasis ms-auto float-end d-none icon-hover-btn d-none"><i class="ri-arrow-right-line fs-5"></i></button>
        <!--end::Brand Image-->
    </div>
    <nav class="pe-app-sidebar-menu nav nav-pills" data-simplebar id="sidebar-simplebar">
        <div class="d-flex align-items-start flex-column w-100">
            <ul class="pe-main-menu list-unstyled">
                <!-- Main Menu -->
                <li class="pe-menu-title">Main</li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseDashboards" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseDashboards">
                        <i class="ri-dashboard-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Dashboards</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseDashboards">
                        <li class="pe-slide-item">
                            <a href="index" class="pe-nav-link">
                                E-Commerece
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="dashboard-school" class="pe-nav-link">
                                School
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="dashboard-analytics" class="pe-nav-link">
                                Sales Analytics
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="dashboard-media" class="pe-nav-link">
                                Social Media
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseApplications" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseApplications">
                        <i class="ri-apps-2-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Applications</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseApplications">
                        <li class="pe-slide-item">
                            <a href="apps-calendar" class="pe-nav-link">
                                Calendar
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="apps-chat" class="pe-nav-link">
                                Chat
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="apps-email" class="pe-nav-link">
                                Email
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="apps-kanban" class="pe-nav-link">
                                Kanban
                            </a>
                        </li>
                        <li class="pe-slide-item pe-has-sub">
                            <a href="#collapseECommerce" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseECommerce">
                                <span class="pe-nav-sub-content">E-Commerce</span>
                                <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                            </a>
                            <ul class="pe-slide-menu collapse" id="collapseECommerce">
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-products" class="pe-nav-link">
                                        Products
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-products-list" class="pe-nav-link">
                                        Product List
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-products-details" class="pe-nav-link">
                                        Product Details
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-create-products" class="pe-nav-link">
                                        Create Product
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-customer" class="pe-nav-link">
                                        Customer
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-customer-details" class="pe-nav-link">
                                        Customer Details
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-order" class="pe-nav-link">
                                        Orders
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-order-details" class="pe-nav-link">
                                        Order Details
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-cart" class="pe-nav-link">
                                        Cart
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-checkout" class="pe-nav-link">
                                        Checkout
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-ecommerce-wishlist" class="pe-nav-link">
                                        Wishlist
                                    </a>
                                </li>
                            </ul>
                        </li>
                        <li class="pe-slide-item pe-has-sub">
                            <a href="#collapseSchool" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseSchool">
                                <span class="pe-nav-sub-content">School</span>
                                <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                            </a>
                            <ul class="pe-slide-menu collapse" id="collapseSchool">
                                <li class="pe-slide-item pe-has-sub">
                                    <a href="#collapseStudents" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseStudents">
                                        <span class="pe-nav-sub-content">Student</span>
                                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                                    </a>
                                    <ul class="pe-slide-menu collapse" id="collapseStudents">
                                        <li class="pe-slide-item">
                                            <a href="apps-school-students" class="pe-nav-link">
                                                All Students
                                            </a>
                                        </li>
                                        <li class="pe-slide-item">
                                            <a href="apps-school-admission-form" class="pe-nav-link">
                                                Admission Form
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                                <li class="pe-slide-item pe-has-sub">
                                    <a href="#collapseTeacher" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseTeacher">
                                        <span class="pe-nav-sub-content">Teacher</span>
                                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                                    </a>
                                    <ul class="pe-slide-menu collapse" id="collapseTeacher">
                                        <li class="pe-slide-item">
                                            <a href="apps-teacher" class="pe-nav-link">
                                                All Teacher
                                            </a>
                                        </li>
                                        <li class="pe-slide-item">
                                            <a href="apps-teacher-schedule" class="pe-nav-link">
                                                Schedule
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-school-parents" class="pe-nav-link">
                                        Parents
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-school-courses" class="pe-nav-link">
                                        Course
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="apps-school-exam" class="pe-nav-link">
                                        Exam
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </li>
                <!-- Pages -->
                <li class="pe-menu-title">Pages</li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseAuth" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseAuth">
                        <i class="ri-user-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Authentication</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseAuth">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Authentication</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="auth-signin" class="pe-nav-link">
                                Sign in
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="auth-signup" class="pe-nav-link">
                                Register
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="auth-forgot-password" class="pe-nav-link">
                                Forgot Password
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="auth-two-step-verify" class="pe-nav-link">
                                Two Step Verification
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="auth-reset-password" class="pe-nav-link">
                                Reset Password
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="auth-email-verify" class="pe-nav-link">
                                Email Verification
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="auth-signout" class="pe-nav-link">
                                Sign out
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapsePages" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapsePages">
                        <i class="ri-pages-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Pages</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapsePages">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Pages</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="pages-starter" class="pe-nav-link">
                                Starter Page
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="pages-profile" class="pe-nav-link">
                                Profile
                            </a>
                        </li>
                        <li class="pe-slide-item pe-has-sub">
                            <a href="#collapseBlogs" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseBlogs">
                                <span class="pe-nav-sub-content">Blogs</span>
                                <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                            </a>
                            <ul class="pe-slide-menu collapse" id="collapseBlogs">
                                <li class="slide pe-nav-content1">
                                    <a href="javascript:void(0)">Blog</a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="pages-blog-list" class="pe-nav-link">
                                        Blog List
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="pages-blog-details" class="pe-nav-link">
                                        Blog Details
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="pages-blog-create" class="pe-nav-link">
                                        Create Blog
                                    </a>
                                </li>
                            </ul>
                        </li>
                        <li class="pe-slide-item">
                            <a href="pages-pricing" class="pe-nav-link">
                                Pricing
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="pages-privacy-policy" class="pe-nav-link">
                                Privacy Policy
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="pages-terms-conditions" class="pe-nav-link">
                                Terms & Conditions
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="pages-timeline" class="pe-nav-link">
                                Timeline
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="pages-faqs" class="pe-nav-link">
                                FAQs
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="pages-billing-subscription" class="pe-nav-link">
                                Billing & Subscription
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="not-authorize" class="pe-nav-link">
                                Not Authorized
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="coming-soon" class="pe-nav-link">
                                Comming Soon
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="under-maintenance" class="pe-nav-link">
                                Maintenance
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="error" class="pe-nav-link">
                                Error
                            </a>
                        </li>
                    </ul>
                </li>
                <!-- Components -->
                <li class="pe-menu-title">Components</li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseBaseUI" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseBaseUI">
                        <i class="ri-pencil-ruler-2-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Base UI</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseBaseUI">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Base UI</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-accordions" class="pe-nav-link">Accordions</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-alerts" class="pe-nav-link">Alert</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-avatars" class="pe-nav-link">Avatar</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-badges" class="pe-nav-link">Badge</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-breadcrumbs" class="pe-nav-link">Breadcrumb</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-buttons" class="pe-nav-link">Buttons</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-button-group" class="pe-nav-link">Button Group</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-card" class="pe-nav-link">Cards</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-cookie" class="pe-nav-link">Cookie</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-carousel" class="pe-nav-link">Carousel</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-dropdowns" class="pe-nav-link">Dropdown</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-images-figures" class="pe-nav-link">Images & Figures</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-links" class="pe-nav-link">Links</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-list" class="pe-nav-link">List Group</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-tabs" class="pe-nav-link">Nav & Tabs</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-pagination" class="pe-nav-link">Pagination</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-popover" class="pe-nav-link">Popovers</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-progress" class="pe-nav-link">Progress</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-spinner" class="pe-nav-link">Spinners</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-separator" class="pe-nav-link">Separator</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-modal" class="pe-nav-link">Modal</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-offcanvas" class="pe-nav-link">Offcanvas</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-placeholders" class="pe-nav-link">Placeholders</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-toast" class="pe-nav-link">Toasts</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-tooltips" class="pe-nav-link">Tooltips</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-typography" class="pe-nav-link">Typography</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-utilities" class="pe-nav-link">Utilities</a>
                        </li>
                    </ul>
                </li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseAdvancedUI" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseAdvancedUI">
                        <i class="ri-color-filter-ai-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Advanced UI</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseAdvancedUI">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Advanced UI</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-block" class="pe-nav-link">Block</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-countup" class="pe-nav-link">Count Up</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-draggable-cards" class="pe-nav-link">Draggable Cards</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-media-player" class="pe-nav-link">Media Player</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-ratings" class="pe-nav-link">Rating</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-ribbons" class="pe-nav-link">Ribbons</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-scrollspy" class="pe-nav-link">Scroll Spy</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-sortable-js" class="pe-nav-link">Sortable JS</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-sweetalert2" class="pe-nav-link">Sweet Alert</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-advance-swiper" class="pe-nav-link">Swiper JS</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-tour" class="pe-nav-link">Tour</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-treeview" class="pe-nav-link">Tree View</a>
                        </li>
                    </ul>
                </li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseFroms" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseFroms">
                        <i class="ri-information-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Forms</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseFroms">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Forms</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-elements" class="pe-nav-link">
                                Input
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-checkboxs-radios" class="pe-nav-link">
                                Checkbox & Radios
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-input-group" class="pe-nav-link">
                                Inout Group
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-select" class="pe-nav-link">
                                Form Select
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-range" class="pe-nav-link">
                                Range Slider
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-input-spin" class="pe-nav-link">
                                Input Spin
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-input-masks" class="pe-nav-link">
                                Input Masks
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-file-uploads" class="pe-nav-link">
                                File Uploads
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-date-picker" class="pe-nav-link">
                                Date,Time Picker
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-floating-labels" class="pe-nav-link">Floating Label</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-layout" class="pe-nav-link">Form Layout</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-editor" class="pe-nav-link">Editor</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-validation" class="pe-nav-link">Form Validation</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-wizards" class="pe-nav-link">Form Wizards</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-form-advanced" class="pe-nav-link">Form Advanced</a>
                        </li>
                    </ul>
                </li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseTables" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseTables">
                        <i class="ri-table-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Tables</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseTables">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Tables</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-tables-basic" class="pe-nav-link">
                                Basic Tables
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-tables-listjs" class="pe-nav-link">
                                List JS Table
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-tables-gridjs" class="pe-nav-link">
                                Grid JS Table
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="ui-tables-datatables" class="pe-nav-link">
                                Datatables
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseCharts" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseCharts">
                        <i class="ri-line-chart-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Charts</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseCharts">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Charts</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="chart-apex-line" class="pe-nav-link">
                                Apex Charts
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="chart-js-chart" class="pe-nav-link">
                                Chartjs Charts
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="echart-chart" class="pe-nav-link">
                                Echart Charts
                            </a>
                        </li>
                    </ul>
                </li>
                <!-- Icons & Maps -->
                <li class="pe-menu-title">Icons & Maps</li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseIcons" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseIcons">
                        <i class="ri-compasses-2-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Icons</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseIcons">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Icons</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="icons-remix" class="pe-nav-link">
                                Remix Icons
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="icons-bootstrap" class="pe-nav-link">
                                Bootstrap Icons
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseMaps" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseMaps">
                        <i class="ri-compass-3-line pe-nav-icon"></i>
                        <span class="pe-nav-content">Maps</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseMaps">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Maps</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="google-maps" class="pe-nav-link">
                                Google Maps
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="maps-leaflet" class="pe-nav-link">
                                Leaflet Maps
                            </a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="maps-vector" class="pe-nav-link">
                                Vector Maps
                            </a>
                        </li>
                    </ul>
                </li>
                <!-- Other -->
                <li class="pe-menu-title">Other</li>
                <li class="pe-slide pe-has-sub">
                    <a href="#collapseMenuLavels" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseMenuLavels">
                        <i class="ri-flow-chart pe-nav-icon"></i>
                        <span class="pe-nav-content">Menu levels</span>
                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                    </a>
                    <ul class="pe-slide-menu collapse" id="collapseMenuLavels">
                        <li class="slide pe-nav-content1">
                            <a href="javascript:void(0)">Menu levels</a>
                        </li>
                        <li class="pe-slide-item">
                            <a href="#!" class="pe-nav-link">
                                Level 1.1
                            </a>
                        </li>
                        <li class="pe-slide-item pe-has-sub">
                            <a href="#collapseMenuLavels2" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseMenuLavels2">
                                <span class="pe-nav-sub-content">Level 1.2</span>
                                <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                            </a>
                            <ul class="pe-slide-menu collapse" id="collapseMenuLavels2">
                                <li class="slide pe-nav-content1">
                                    <a href="javascript:void(0)">Level 1.2</a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="#!" class="pe-nav-link">
                                        Level 2.1
                                    </a>
                                </li>
                                <li class="pe-slide-item">
                                    <a href="#!" class="pe-nav-link">
                                        Level 2.2
                                    </a>
                                </li>
                                <li class="pe-slide-item pe-has-sub">
                                    <a href="#collapseMenuLavels3" class="pe-nav-link" data-bs-toggle="collapse" aria-expanded="false" aria-controls="collapseMenuLavels3">
                                        <span class="pe-nav-sub-content">Level 2.3</span>
                                        <i class="ri-arrow-down-s-line pe-nav-arrow"></i>
                                    </a>
                                    <ul class="pe-slide-menu collapse" id="collapseMenuLavels3">
                                        <li class="slide pe-nav-content1">
                                            <a href="javascript:void(0)">Level 2.3</a>
                                        </li>
                                        <li class="pe-slide-item">
                                            <a href="#!" class="pe-nav-link">
                                                Level 3.1
                                            </a>
                                        </li>
                                        <li class="pe-slide-item">
                                            <a href="#!" class="pe-nav-link">
                                                Level 3.2
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </li>
            </ul>
            <!-- Widgets -->
            <div class="sidebar-widget text-center">
                <img src="assets/images/sidebar-widget.png" alt="Widget Image">
                <p class="text-muted fw-semibold">Click here to update the new version</p>
                <button class="btn btn-primary rounded-pill w-100">Update Now</button>
            </div>
        </div>
    </nav>
</aside>
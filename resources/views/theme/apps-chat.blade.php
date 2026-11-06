@extends('partials.layouts.master')

@section('title', 'Chat | Urbix Admin & Dashboards Template')
@section('title-sub', 'Applications')
@section('pagetitle', 'Chat')

@section('content')


    <!-- begin::App -->

    <div class="row chat-wrapper g-0">
        <div class="border-end col-xxl-3 col-xl-4">
            <div class="app-chat-sidebar active" id="app-chat-sidebar">
                <div class="p-5">
                    <div class="mb-2">
                        <div class="position-relative d-flex align-items-center justify-content-between">
                            <h4 class="mb-0">Chat Box</h4>
                            <div class="position-absolute d-xl-none" style="right: -38px;" data-bs-toggle="tooltip"
                                data-bs-trigger="hover" data-bs-placement="bottom" aria-label="Close Button"
                                data-bs-original-title="Close Button" aria-describedby="tooltip430017">
                                <a href="javascript:void(0)" class="bg-light p-2 rounded-pill text-success"
                                    id="close-chat-sidebar">
                                    <i class="ri-arrow-left-double-fill fs-5"></i>
                                </a>
                            </div>
                            <div class="d-xl-block d-none" data-bs-toggle="tooltip" data-bs-trigger="hover"
                                data-bs-placement="bottom" aria-label="Add Contact" data-bs-original-title="Add Contact"
                                aria-describedby="tooltip430017">
                                <a href="javascript:void(0)" class="px-0 border-0 btn btn-sm">
                                    <i class="ri-user-add-line fs-5"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <ul class="my-4 nav nav-tabs-bordered nav-justified" role="tablist">
                        <li class="nav-item" role="presentation">
                            <a class="nav-link active" data-bs-toggle="tab" href="#chats" role="tab"
                                aria-selected="true">
                                Chats
                            </a>
                        </li>
                    </ul>
                    <div class="text-muted tab-content">
                        <div class="tab-pane active show" id="chats" role="tabpanel">
                            <div class="mx-n5 chat-room-list" data-simplebar>
                                <ul id="chatList" class="mb-0 list-unstyled">
                                    <li id="chat1" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                            <img src="assets/images/avatar/avatar-1.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">10:20am</span>
                                                Gaston Lapierre
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">How are you today?</span>
                                                <i class="text-success bi bi-check-all"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat2" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                            <img src="assets/images/avatar/avatar-12.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">Now</span>
                                                Sophia Turner
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="text-success">Typing...</span></i>
                                                <span class="bg-success-subtle text-success badge">3</span>
                                            </p>
                                        </div>
                                    </li>

                                    <li class="d-flex align-items-center gap-3 mb-2 px-4 py-3 active">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                            <img src="assets/images/avatar/avatar-2.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">12:45pm</span>
                                                Sophia Turner
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">Hey, are you free?</span>
                                                <i class="text-muted bi bi-check-all"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat3" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                            <img src="assets/images/avatar/avatar-3.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">9:15am</span>
                                                Oliver Smith
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">Meeting at 2 PM?</span>
                                                <i class="text-success bi bi-check-all"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat4" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper">
                                            <img src="assets/images/avatar/avatar-4.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">8:30am</span>
                                                Emily Johnson
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">See you later!</span>
                                                <i class="text-muted bi bi-check-all"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat5" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                            <img src="assets/images/avatar/avatar-5.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">7:50am</span>
                                                William Brown
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">Call me when you're free.</span>
                                                <i class="text-success bi bi-check-all"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat6" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper">
                                            <img src="assets/images/avatar/avatar-6.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">6:20am</span>
                                                Ava Martinez
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">Good morning!</span>
                                                <i class="text-muted bi bi-check"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat7" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                            <img src="assets/images/avatar/avatar-7.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">Yesterday</span>
                                                Ethan Wilson
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">Let's catch up soon!</span>
                                                <i class="text-success bi bi-check-all"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat8" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                            <img src="assets/images/avatar/avatar-8.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">Yesterday</span>
                                                Mia Davis
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">Did you see my message?</span>
                                                <i class="text-muted bi bi-check"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat9" class="d-flex align-items-center gap-3 mb-2 px-4 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper">
                                            <img src="assets/images/avatar/avatar-9.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">Yesterday</span>
                                                Lucas Anderson
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">Thanks for your help!</span>
                                                <i class="text-success bi bi-check-all"></i>
                                            </p>
                                        </div>
                                    </li>

                                    <li id="chat10" class="d-flex align-items-center gap-3 px-5 py-3">
                                        <div class="position-relative flex-shrink-0 avatar-wrapper active">
                                            <img src="assets/images/avatar/avatar-10.jpg" class="rounded-circle avatar-lg"
                                                height="36" alt="avatar">
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h6 class="my-0">
                                                <span class="float-end text-body fs-12 fw-normal">Yesterday</span>
                                                Amelia White
                                            </h6>
                                            <p
                                                class="d-flex align-items-end justify-content-between mt-1 mb-0 text-muted fs-13">
                                                <span class="w-75">Let's plan for the weekend!</span>
                                                <i class="text-muted bi bi-check-all"></i>
                                            </p>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xxl-9 col-xl-8">
            <div class="overflow-hidden">
                <div class="p-5 border-bottom">
                    <div class="align-items-center row">
                        <div class="col-sm-4 col-8">
                            <div class="d-flex align-items-center">
                                <div class="d-block flex-shrink-0 me-4 d-xl-none">
                                    <a href="#!" class="btn btn-light icon-btn fs-5" id="open-chat-sidebar"><i
                                            class="ri-menu-2-fill"></i></a>
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div id="selectedUserProfile" class="d-flex align-items-center gap-2">
                                        <div id="selectedUserAvatarWrapper" class="flex-shrink-0 avatar-wrapper active">
                                            <img id="selectedUserAvatar" src="assets/images/avatar/avatar-12.jpg"
                                                class="rounded-circle avatar-lg" alt="avatar image">
                                            <span class="user-status"></span>
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h5 id="selectedUserName" class="mb-0 text-truncate fs-15">
                                                <a id="selectedUserLink" class="text-reset" data-bs-toggle="offcanvas"
                                                    href="#userProfileCanvasExample"
                                                    aria-controls="userProfileCanvasExample"></a>Sophia Turner
                                            </h5>
                                            <p id="selectedUserStatus"
                                                class="mb-0 text-muted text-truncate fs-14 userStatus">
                                                <small>Online</small></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-8 col-4">
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-light icon-btn"><i
                                        class="bi bi-telephone-inbound"></i></button>
                                <button type="button" class="btn btn-light icon-btn"><i
                                        class="bi bi-camera-video"></i></button>
                                <div class="dropdown">
                                    <a href="javascript:void(0)" class="btn btn-light icon-btn" data-bs-toggle="dropdown"
                                        aria-expanded="true" aria-expanded="false"> <i
                                            class="bi bi-three-dots-vertical"></i></a>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <a class="dropdown-item" href="javascript:void(0)">View Contact</a>
                                        <a class="dropdown-item" href="javascript:void(0)">Mute Chat</a>
                                        <a class="dropdown-item" href="javascript:void(0)">Block</a>
                                        <a class="dropdown-item" href="javascript:void(0)" id="clearChat">Clear Chat</a>
                                        <a class="dropdown-item" href="javascript:void(0)">Report</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="p-5 chat-conversation" id="chat-conversation" data-simplebar>
                    <ul id="chat-list" class="list-unstyled chat-conversation-list"></ul>
                </div>
                <div class="p-5 border-top">
                    <form id="chatinput-form" enctype="multipart/form-data">
                        <div class="align-items-center row g-0">
                            <div class="col">
                                <input type="text" class="form-control" id="chat-input"
                                    placeholder="Type your message..." autocomplete="off">
                            </div>
                            <div class="col-auto">
                                <div class="ms-2 chat-input-links">
                                    <div class="d-flex align-items-center gap-4">
                                        <a href="#!" class="text-body link fs-4" data-bs-toggle="tooltip"
                                            data-bs-html="true" title="Attach a Link">
                                            <i class="ri-link-m"></i>
                                        </a>
                                        <div class="file-upload">
                                            <a href="#!" class="text-body link fs-4" data-bs-toggle="tooltip"
                                                data-bs-html="true" title="Add a File">
                                                <input class="file-upload-item" type="file">
                                                <i class="ri-file-add-line"></i>
                                            </a>
                                        </div>
                                        <a href="#!" class="text-body link fs-4" data-bs-toggle="tooltip"
                                            data-bs-html="true" title="Send an Emoji">
                                            <i class="ri-emoji-sticker-line"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-auto">
                                <div class="ms-2">
                                    <div class="links-list-item">
                                        <button type="submit" class="btn btn-light">
                                            <i class="bi bi-send-fill"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="app-chat-overlay" id="appChatOverlay"></div>
    </div>

    </div><!--End container-fluid-->
    </main>
    </div><!--End app-wrapper-->

@endsection

@section('js')

    <script src="assets/js/app/chat.init.js"></script>
    <!-- App js -->
    <script src="assets/js/app.js"></script>
@endsection
